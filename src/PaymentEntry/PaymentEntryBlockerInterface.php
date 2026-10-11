<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentEntry;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Stops the customer from paying an order: the shop `sylius_shop_order_pay` route (reached
 * after checkout and from the order page) redirects to the thank-you page with a flash message,
 * and the shop API refuses to create or update payment requests of the order. Implementations
 * are autoconfigured with the `tmonier_order_hold.payment_entry_blocker` tag.
 */
interface PaymentEntryBlockerInterface
{
    /** Null when the order can be paid as far as this blocker is concerned. */
    public function getBlock(OrderInterface $order): ?PaymentEntryBlock;
}
