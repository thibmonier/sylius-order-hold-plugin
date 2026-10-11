<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Tmonier\SyliusOrderHoldPlugin\Functional\OrderHoldTestTrait;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\HoldAwareUnpaidOrdersStateUpdater;

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

        $this->runCommand();

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

        $this->runCommand();

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

        $this->runCommand();

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($guarded)->getState());
        foreach ($others as $order) {
            self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($order)->getState());
        }
    }

    private function runCommand(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('sylius:cancel-unpaid-orders'));

        self::assertSame(0, $tester->execute([]));
    }
}
