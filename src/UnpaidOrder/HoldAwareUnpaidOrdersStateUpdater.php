<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\OrderRepository as CoreOrderRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Sylius\Component\Order\OrderTransitions;

/**
 * Decorates `sylius.updater.unpaid_orders_state` (used by `sylius:cancel-unpaid-orders`). Same
 * selection as `OrderRepository::findOrdersUnpaidSince()`, but:
 * - the orders are read by increasing id (keyset pagination), so an order that is held or whose
 *   cancellation is refused or fails is never read twice and the command always terminates (the
 *   core updater re-runs the same query until it returns nothing);
 * - each cancellation is flushed on its own; a failing one is logged and skipped, the entity
 *   manager is cleared (reset when the failure closed it) and the run goes on;
 * - held orders are skipped (exclusions) or filtered in SQL (query extensions, whose parameters
 *   must not reuse the `tmonier_oh_` prefix);
 * - a hold can make the expiration period run from a later date than checkout completion;
 * - an application override of `findOrdersUnpaidSince()` is respected: its result restricts the
 *   candidates.
 */
final class HoldAwareUnpaidOrdersStateUpdater implements UnpaidOrdersStateUpdaterInterface
{
    public const PARAMETER_PREFIX = 'tmonier_oh_';

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param class-string<OrderInterface> $orderClass
     * @param iterable<UnpaidOrderCandidateQueryExtensionInterface> $queryExtensions
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly string $orderClass,
        private readonly StateMachineInterface $stateMachine,
        private readonly UnpaidOrderHoldResolverInterface $holdResolver,
        private readonly iterable $queryExtensions,
        private readonly string $expirationPeriod,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = 100,
        private readonly ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function cancel(): void
    {
        $terminalDate = new \DateTimeImmutable('-' . $this->expirationPeriod);
        $restrictedIds = $this->idsFromRepositoryOverride($terminalDate);
        if ([] === $restrictedIds) {
            return;
        }

        $lastId = null;
        $held = 0;

        while ([] !== $orders = $this->findCandidates($terminalDate, $lastId, $restrictedIds)) {
            foreach ($orders as $order) {
                $lastId = $order->getId();
                if (!$this->holdResolver->isExpired($order, $terminalDate)) {
                    ++$held;

                    continue;
                }

                if (!$this->cancelOrder($order)) {
                    // The entity manager was cleared: the other orders of the batch are detached.
                    continue 2;
                }
            }

            $this->entityManager->clear();
        }

        if ($held > 0) {
            $this->logger->info(sprintf('%d expired unpaid order(s) kept by an order hold.', $held));
        }
    }

    /**
     * Null when the repository is the core one; otherwise the ids its `findOrdersUnpaidSince()`
     * returns, which then restrict the candidates.
     *
     * @return list<mixed>|null
     */
    private function idsFromRepositoryOverride(\DateTimeImmutable $terminalDate): ?array
    {
        $declaringClass = (new \ReflectionMethod($this->orderRepository, 'findOrdersUnpaidSince'))->getDeclaringClass()->getName();
        if (CoreOrderRepository::class === $declaringClass) {
            return null;
        }

        $this->logger->notice(sprintf('Unpaid orders are restricted to the ones returned by %s::findOrdersUnpaidSince().', $declaringClass));
        $ids = array_map(static fn (OrderInterface $order): mixed => $order->getId(), $this->orderRepository->findOrdersUnpaidSince($terminalDate));
        $this->entityManager->clear();

        return array_values($ids);
    }

    /**
     * @param list<mixed>|null $restrictedIds
     *
     * @return list<OrderInterface>
     */
    private function findCandidates(\DateTimeImmutable $terminalDate, mixed $lastId, ?array $restrictedIds): array
    {
        $parameters = [
            self::PARAMETER_PREFIX . 'checkoutState' => OrderCheckoutStates::STATE_COMPLETED,
            self::PARAMETER_PREFIX . 'paymentState' => OrderPaymentStates::STATE_AWAITING_PAYMENT,
            self::PARAMETER_PREFIX . 'orderState' => OrderInterface::STATE_NEW,
            self::PARAMETER_PREFIX . 'terminalDate' => $terminalDate,
        ];

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from($this->orderClass, 'o')
            ->andWhere(sprintf('o.checkoutState = :%scheckoutState', self::PARAMETER_PREFIX))
            ->andWhere(sprintf('o.paymentState = :%spaymentState', self::PARAMETER_PREFIX))
            ->andWhere(sprintf('o.state = :%sorderState', self::PARAMETER_PREFIX))
            ->andWhere(sprintf('o.checkoutCompletedAt < :%sterminalDate', self::PARAMETER_PREFIX))
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(max(1, $this->batchSize))
        ;

        if (null !== $lastId) {
            $queryBuilder->andWhere(sprintf('o.id > :%slastId', self::PARAMETER_PREFIX));
            $parameters[self::PARAMETER_PREFIX . 'lastId'] = $lastId;
        }
        if (null !== $restrictedIds) {
            $queryBuilder->andWhere(sprintf('o.id IN (:%sids)', self::PARAMETER_PREFIX));
            $parameters[self::PARAMETER_PREFIX . 'ids'] = $restrictedIds;
        }
        foreach ($parameters as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }

        foreach ($this->queryExtensions as $queryExtension) {
            $queryExtension->applyToCandidateQuery($queryBuilder, 'o');
            $this->assertParametersUnchanged($queryBuilder, $parameters, $queryExtension);
        }

        /** @var list<OrderInterface> $orders */
        $orders = $queryBuilder->getQuery()->getResult();

        return $orders;
    }

    /** @param array<string, mixed> $parameters */
    private function assertParametersUnchanged(QueryBuilder $queryBuilder, array $parameters, UnpaidOrderCandidateQueryExtensionInterface $queryExtension): void
    {
        foreach ($parameters as $name => $value) {
            $parameter = $queryBuilder->getParameter($name);
            if (!$parameter instanceof Parameter || $parameter->getValue() !== $value) {
                throw new \LogicException(sprintf(
                    'The unpaid order query extension %s changed the "%s" parameter of the candidate query: prefix the parameters of extensions with something else than "%s".',
                    $queryExtension::class,
                    $name,
                    self::PARAMETER_PREFIX,
                ));
            }
        }
    }

    /** False when the cancellation failed and the entity manager was cleared. */
    private function cancelOrder(OrderInterface $order): bool
    {
        try {
            if (!$this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)) {
                $this->logger->warning(sprintf('Unpaid order #%s cannot be cancelled.', (string) $order->getNumber()));

                return true;
            }

            $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
            $this->entityManager->flush();

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('An error occurred while cancelling unpaid order #%s', (string) $order->getNumber()),
                ['exception' => $exception, 'message' => $exception->getMessage()],
            );
            if (!$this->entityManager->isOpen()) {
                // E.g. a constraint violation during the flush: reset the manager and go on
                // after this order (the keyset pagination never reads it again).
                $this->resetEntityManager($exception);
            }
            // Drops the half-applied changes of this order (state, payments, inventory).
            $this->entityManager->clear();

            return false;
        }
    }

    private function resetEntityManager(\Throwable $exception): void
    {
        if (null === $this->managerRegistry) {
            throw $exception;
        }

        $this->managerRegistry->resetManager();
        $entityManager = $this->managerRegistry->getManagerForClass($this->orderClass);
        if (!$entityManager instanceof EntityManagerInterface || !$entityManager->isOpen()) {
            throw $exception;
        }
        $this->entityManager = $entityManager;
    }
}
