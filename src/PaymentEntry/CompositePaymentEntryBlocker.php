<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentEntry;

use Sylius\Component\Core\Model\OrderInterface;

/** Asks every tagged blocker; the first block wins. */
final class CompositePaymentEntryBlocker implements PaymentEntryBlockerInterface
{
    /** @param iterable<PaymentEntryBlockerInterface> $blockers */
    public function __construct(private readonly iterable $blockers)
    {
    }

    public function getBlock(OrderInterface $order): ?PaymentEntryBlock
    {
        foreach ($this->blockers as $blocker) {
            $block = $blocker->getBlock($order);
            if (null !== $block) {
                return $block;
            }
        }

        return null;
    }
}
