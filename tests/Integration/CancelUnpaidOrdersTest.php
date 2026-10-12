<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\OrderRepository as CoreOrderRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Tmonier\SyliusOrderHoldPlugin\Functional\OrderHoldTestTrait;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\HoldAwareUnpaidOrdersStateUpdater;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCandidateQueryExtensionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHoldResolverInterface;

/** `sylius:cancel-unpaid-orders` with the default 5-day expiration and batches of 2 orders (test application). */
final class CancelUnpaidOrdersTest extends KernelTestCase
{
    use OrderHoldTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
        // Safety net: a regression to the core re-query loop must fail instead of hanging.
        set_time_limit(120);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        $this->rollBack();
        parent::tearDown();
    }

    public function testTheUpdaterIsDecorated(): void
    {
        self::assertInstanceOf(HoldAwareUnpaidOrdersStateUpdater::class, self::getContainer()->get('sylius.updater.unpaid_orders_state'));
        self::assertInstanceOf(HoldAwareUnpaidOrdersStateUpdater::class, self::getContainer()->get(UnpaidOrdersStateUpdaterInterface::class));
    }

    public function testHeldOrdersAreNotCancelledAndTheCommandTerminates(): void
    {
        $expired = $this->unpaidOrder('10 days');
        $recent = $this->unpaidOrder('1 day');
        // More held orders than the batch size: the core updater would re-read them forever.
        $held = [];
        for ($i = 0; $i < 5; ++$i) {
            $held[] = $order = $this->unpaidOrder('10 days');
            $this->holds()->hold($order);
        }
        $expired2 = $this->unpaidOrder('20 days');

        $this->runCancelUnpaidOrders();

        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($expired)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($expired2)->getState());
        self::assertSame(OrderInterface::STATE_NEW, $this->reload($recent)->getState());
        foreach ($held as $order) {
            self::assertSame(OrderInterface::STATE_NEW, $this->reload($order)->getState());
            self::assertSame('awaiting_payment', $this->reload($order)->getPaymentState());
        }
    }

    public function testTheExpirationPeriodCanRunFromAHoldDate(): void
    {
        $approvedYesterday = $this->unpaidOrder('30 days');
        $this->holds()->hold($approvedYesterday, new \DateTimeImmutable('-1 day'));
        $approvedLongAgo = $this->unpaidOrder('30 days');
        $this->holds()->hold($approvedLongAgo, new \DateTimeImmutable('-6 days'));

        $this->runCancelUnpaidOrders();

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($approvedYesterday)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($approvedLongAgo)->getState());
    }

    public function testARefusedCancellationDoesNotMakeTheCommandLoop(): void
    {
        $guarded = $this->unpaidOrder('10 days');
        $guardedNumber = $guarded->getNumber();
        $others = [$this->unpaidOrder('10 days'), $this->unpaidOrder('11 days'), $this->unpaidOrder('12 days')];

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener('workflow.sylius_order.guard.cancel', static function (GuardEvent $event) use ($guardedNumber): void {
            $order = $event->getSubject();
            if ($order instanceof OrderInterface && $order->getNumber() === $guardedNumber) {
                $event->setBlocked(true, 'Guarded by the test');
            }
        });

        $this->runCancelUnpaidOrders();

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($guarded)->getState());
        foreach ($others as $order) {
            self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($order)->getState());
        }
    }

    public function testAFailingCancellationIsSkippedAndTheOtherOrdersAreCancelled(): void
    {
        $before = $this->unpaidOrder('10 days');
        $failing = $this->unpaidOrder('10 days');
        $after = [$this->unpaidOrder('10 days'), $this->unpaidOrder('11 days'), $this->unpaidOrder('12 days')];
        $failingNumber = $failing->getNumber();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // Like NotEnoughUnitsOnHoldException thrown by the inventory release on cancel.
        $dispatcher->addListener('workflow.sylius_order.completed.cancel', static function (CompletedEvent $event) use ($failingNumber): void {
            $order = $event->getSubject();
            if ($order instanceof OrderInterface && $order->getNumber() === $failingNumber) {
                throw new \RuntimeException('Not enough units on hold');
            }
        });

        $this->runCancelUnpaidOrders();

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($failing)->getState());
        self::assertSame('awaiting_payment', $this->reload($failing)->getPaymentState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($before)->getState());
        foreach ($after as $order) {
            self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($order)->getState());
        }
    }

    public function testAFailureDuringTheFlushResetsTheEntityManagerAndTheRunGoesOn(): void
    {
        $before = $this->unpaidOrder('10 days');
        $failing = $this->unpaidOrder('10 days');
        $after = [$this->unpaidOrder('10 days'), $this->unpaidOrder('11 days'), $this->unpaidOrder('12 days')];
        $failingNumber = $failing->getNumber();
        $duplicateNumber = $before->getNumber();
        $this->entityManager()->clear();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // Unique constraint violation at flush: the entity manager gets closed.
        $dispatcher->addListener('workflow.sylius_order.completed.cancel', static function (CompletedEvent $event) use ($failingNumber, $duplicateNumber): void {
            $order = $event->getSubject();
            if ($order instanceof OrderInterface && $order->getNumber() === $failingNumber) {
                $order->setNumber($duplicateNumber);
            }
        });

        $this->runCancelUnpaidOrders();

        $connection = $this->entityManager()->getConnection();
        self::assertTrue($this->entityManager()->isOpen());
        self::assertSame('new', $connection->fetchOne('SELECT state FROM sylius_order WHERE id = ?', [$failing->getId()]));
        foreach ([$before, ...$after] as $order) {
            self::assertSame('cancelled', $connection->fetchOne('SELECT state FROM sylius_order WHERE id = ?', [$order->getId()]));
        }
    }

    public function testAnOverrideOfFindOrdersUnpaidSinceRestrictsTheCandidates(): void
    {
        $kept = $this->unpaidOrder('10 days');
        $cancelled = $this->unpaidOrder('10 days');
        $keptId = $kept->getId();
        $entityManager = $this->entityManager();
        /** @var class-string<OrderInterface> $orderClass */
        $orderClass = self::getContainer()->getParameter('sylius.model.order.class');
        $repository = new class($entityManager, $entityManager->getClassMetadata($orderClass), $keptId) extends CoreOrderRepository {
            /** @param ClassMetadata<OrderInterface> $class */
            public function __construct(EntityManagerInterface $entityManager, ClassMetadata $class, private readonly mixed $excludedId)
            {
                parent::__construct($entityManager, $class);
            }

            public function findOrdersUnpaidSince(\DateTimeInterface $terminalDate, ?int $limit = null): array
            {
                return array_values(array_filter(parent::findOrdersUnpaidSince($terminalDate, $limit), fn (OrderInterface $order): bool => $order->getId() !== $this->excludedId));
            }
        };

        $this->updater($repository)->cancel();

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($kept)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($cancelled)->getState());
    }

    public function testAQueryExtensionExcludesOrdersInSql(): void
    {
        $kept = $this->unpaidOrder('10 days');
        $cancelled = $this->unpaidOrder('10 days');
        $extension = new class($kept->getId()) implements UnpaidOrderCandidateQueryExtensionInterface {
            public function __construct(private readonly mixed $excludedId)
            {
            }

            public function applyToCandidateQuery(QueryBuilder $queryBuilder, string $rootAlias): void
            {
                $queryBuilder->andWhere(sprintf('%s.id <> :app_excluded', $rootAlias))->setParameter('app_excluded', $this->excludedId);
            }
        };

        $this->updater(null, [$extension])->cancel();

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($kept)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($cancelled)->getState());
    }

    public function testAQueryExtensionCannotOverwriteTheBaseParameters(): void
    {
        $order = $this->unpaidOrder('10 days');
        $extension = new class() implements UnpaidOrderCandidateQueryExtensionInterface {
            public function applyToCandidateQuery(QueryBuilder $queryBuilder, string $rootAlias): void
            {
                $queryBuilder->setParameter('tmonier_oh_paymentState', 'paid');
            }
        };

        try {
            $this->updater(null, [$extension])->cancel();
            self::fail('The overwritten parameter was accepted.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('"tmonier_oh_paymentState"', $exception->getMessage());
        }
        self::assertSame(OrderInterface::STATE_NEW, $this->reload($order)->getState());
    }

    /**
     * @param OrderRepositoryInterface<OrderInterface>|null $repository
     * @param list<UnpaidOrderCandidateQueryExtensionInterface> $extensions
     */
    private function updater(?OrderRepositoryInterface $repository = null, array $extensions = []): HoldAwareUnpaidOrdersStateUpdater
    {
        $container = self::getContainer();
        $orderRepository = $repository ?? $container->get('sylius.repository.order');
        self::assertInstanceOf(OrderRepositoryInterface::class, $orderRepository);
        $stateMachine = $container->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);
        $holdResolver = $container->get('tmonier_order_hold.resolver.unpaid_order_hold');
        self::assertInstanceOf(UnpaidOrderHoldResolverInterface::class, $holdResolver);
        /** @var class-string<OrderInterface> $orderClass */
        $orderClass = $container->getParameter('sylius.model.order.class');

        return new HoldAwareUnpaidOrdersStateUpdater($this->entityManager(), $orderRepository, $orderClass, $stateMachine, $holdResolver, $extensions, '5 days', new NullLogger(), 2);
    }

    private function runCancelUnpaidOrders(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('sylius:cancel-unpaid-orders'));

        self::assertSame(0, $tester->execute([]));
    }
}
