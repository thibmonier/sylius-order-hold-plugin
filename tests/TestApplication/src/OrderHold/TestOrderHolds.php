<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\OrderHold;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHold;

/** Holds, refusals and blocks keyed by order number and payment method code, set by the tests. */
final class TestOrderHolds implements UnpaidOrderCancellationExclusionInterface, PaymentMethodEligibilityCheckerInterface, PaymentEntryBlockerInterface
{
    /** @var array<string, UnpaidOrderHold> */
    private array $holds = [];

    /** @var array<string, IneligibilityReason> */
    private array $refusedMethods = [];

    /** @var array<string, true> */
    private array $blockedOrders = [];

    public function hold(OrderInterface $order, ?\DateTimeInterface $expirationStart = null): void
    {
        $this->holds[(string) $order->getNumber()] = null === $expirationStart
            ? UnpaidOrderHold::indefinitely('test')
            : UnpaidOrderHold::since($expirationStart, 'test');
    }

    public function refuseMethod(PaymentMethodInterface $paymentMethod, IneligibilityReason $reason = IneligibilityReason::Restricted): void
    {
        $this->refusedMethods[(string) $paymentMethod->getCode()] = $reason;
    }

    public function blockPaymentEntry(OrderInterface $order): void
    {
        $this->blockedOrders[(string) $order->getNumber()] = true;
    }

    public function getHold(OrderInterface $order): ?UnpaidOrderHold
    {
        return $this->holds[(string) $order->getNumber()] ?? null;
    }

    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
    {
        $reason = $this->refusedMethods[(string) $paymentMethod->getCode()] ?? null;

        return null === $reason ? null : new PaymentMethodIneligibility($reason);
    }

    public function getBlock(OrderInterface $order): ?PaymentEntryBlock
    {
        return isset($this->blockedOrders[(string) $order->getNumber()]) ? new PaymentEntryBlock() : null;
    }
}
