<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Keeps orders out of `sylius:cancel-unpaid-orders`. Implementations are autoconfigured with the
 * `tmonier_order_hold.unpaid_order_cancellation_exclusion` tag.
 *
 * Never put a guard on the `sylius_order.cancel` transition instead: the core updater re-reads
 * the same unpaid orders until none is left, so a refused cancellation makes the command loop
 * forever.
 */
interface UnpaidOrderCancellationExclusionInterface
{
    /**
     * Called for every completed, unpaid order older than the expiration period.
     * Returns null when this exclusion has nothing to say about the order.
     */
    public function getHold(OrderInterface $order): ?UnpaidOrderHold;
}
