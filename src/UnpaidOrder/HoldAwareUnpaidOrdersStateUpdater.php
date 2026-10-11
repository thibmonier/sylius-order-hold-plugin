<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\Exception\StateMachineExecutionException;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Sylius\Component\Order\OrderTransitions;

/**
 * Decorates `sylius.updater.unpaid_orders_state` (used by `sylius:cancel-unpaid-orders`). Same
 * selection as `OrderRepository::findOrdersUnpaidSince()`, but:
 * - the orders are read by increasing id (keyset pagination), so an order that is held or whose
 *   cancellation fails is never read twice and the command always terminates (the core updater
 *   re-runs the same query until it returns nothing);
 * - held orders are skipped (exclusions) or filtered in SQL (query extensions);
 * - a hold can make the expiration period run from a later date than checkout completion.
 */
final class HoldAwareUnpaidOrdersStateUpdater implements UnpaidOrdersStateUpdaterInterface
{
    /**
     * @param class-string<OrderInterface> $orderClass
     * @param iterable<UnpaidOrderCandidateQueryExtensionInterface> $queryExtensions
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $orderClass,
        private readonly StateMachineInterface $stateMachine,
        private readonly UnpaidOrderHoldResolverInterface $holdResolver,
        private readonly iterable $queryExtensions,
        private readonly string $expirationPeriod,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = 100,
    ) {
    }

    public function cancel(): void
    {
        $terminalDate = new \DateTimeImmutable('-' . $this->expirationPeriod);
        $lastId = null;
        $held = 0;

        while ([] !== $orders = $this->findCandidates($terminalDate, $lastId)) {
            foreach ($orders as $order) {
                $lastId = $order->getId();
                if (!$this->holdResolver->isExpired($order, $terminalDate)) {
                    ++$held;

                    continue;
                }

                $this->cancelOrder($order);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        if ($held > 0) {
            $this->logger->info(sprintf('%d expired unpaid order(s) kept by an order hold.', $held));
        }
    }

    /** @return list<OrderInterface> */
    private function findCandidates(\DateTimeImmutable $terminalDate, mixed $lastId): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from($this->orderClass, 'o')
            ->andWhere('o.checkoutState = :checkoutState')
            ->andWhere('o.paymentState = :paymentState')
            ->andWhere('o.state = :orderState')
            ->andWhere('o.checkoutCompletedAt < :terminalDate')
            ->setParameter('checkoutState', OrderCheckoutStates::STATE_COMPLETED)
            ->setParameter('paymentState', OrderPaymentStates::STATE_AWAITING_PAYMENT)
            ->setParameter('orderState', OrderInterface::STATE_NEW)
            ->setParameter('terminalDate', $terminalDate)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(max(1, $this->batchSize))
        ;

        if (null !== $lastId) {
            $queryBuilder->andWhere('o.id > :lastId')->setParameter('lastId', $lastId);
        }

        foreach ($this->queryExtensions as $queryExtension) {
            $queryExtension->applyToCandidateQuery($queryBuilder, 'o');
        }

        /** @var list<OrderInterface> $orders */
        $orders = $queryBuilder->getQuery()->getResult();

        return $orders;
    }

    private function cancelOrder(OrderInterface $order): void
    {
        try {
            if (!$this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)) {
                $this->logger->warning(sprintf('Unpaid order #%s cannot be cancelled.', (string) $order->getNumber()));

                return;
            }

            $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        } catch (StateMachineExecutionException $exception) {
            $this->logger->error(
                sprintf('An error occurred while cancelling unpaid order #%s', (string) $order->getNumber()),
                ['exception' => $exception, 'message' => $exception->getMessage()],
            );
        }
    }
}
