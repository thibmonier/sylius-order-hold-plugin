<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\AccountOwner;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Service `tmonier_order_hold.resolver.account_owner`. A single owner model applies per shop:
 * plugins that change it (e.g. companies) decorate the service and fall back to the decorated
 * resolver.
 */
interface AccountOwnerResolverInterface
{
    /** Null for an order without customer. */
    public function resolveForOrder(OrderInterface $order): ?AccountOwner;

    /** Null for a customer that is not persisted yet. */
    public function resolveForCustomer(CustomerInterface $customer): ?AccountOwner;
}
