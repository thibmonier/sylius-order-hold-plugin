<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

use Sylius\Component\Core\Model\OrderInterface;

interface UnpaidOrderHoldResolverInterface
{
    /**
     * Combines the holds of every exclusion: an indefinite hold wins, otherwise the latest
     * expiration start. Null when no exclusion holds the order.
     */
    public function resolve(OrderInterface $order): ?UnpaidOrderHold;

    /** Whether the order must be cancelled when its default expiration started before `$terminalDate`. */
    public function isExpired(OrderInterface $order, \DateTimeInterface $terminalDate): bool;
}
