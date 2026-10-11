<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\AccountOwner;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/** Default owner: the customer of the order. */
final class CustomerAccountOwnerResolver implements AccountOwnerResolverInterface
{
    public function resolveForOrder(OrderInterface $order): ?AccountOwner
    {
        $customer = $order->getCustomer();

        return $customer instanceof CustomerInterface ? $this->resolveForCustomer($customer) : null;
    }

    public function resolveForCustomer(CustomerInterface $customer): ?AccountOwner
    {
        $id = $customer->getId();
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        return new AccountOwner(AccountOwner::TYPE_CUSTOMER, (string) $id, $customer);
    }
}
