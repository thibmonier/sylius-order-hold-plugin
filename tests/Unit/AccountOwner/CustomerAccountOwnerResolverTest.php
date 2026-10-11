<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\AccountOwner;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\CustomerAccountOwnerResolver;

final class CustomerAccountOwnerResolverTest extends TestCase
{
    public function testTheOwnerOfAnOrderIsItsCustomer(): void
    {
        $customer = $this->customer(7);
        $order = new Order();
        $order->setCustomer($customer);

        $owner = (new CustomerAccountOwnerResolver())->resolveForOrder($order);

        self::assertNotNull($owner);
        self::assertSame(AccountOwner::TYPE_CUSTOMER, $owner->getType());
        self::assertSame('7', $owner->getIdentifier());
        self::assertSame($customer, $owner->getSubject());
        self::assertTrue($owner->equals(new AccountOwner('customer', '7', new \stdClass())));
        self::assertFalse($owner->equals(new AccountOwner('company', '7', new \stdClass())));
    }

    public function testOrdersWithoutCustomerAndNewCustomersHaveNoOwner(): void
    {
        $resolver = new CustomerAccountOwnerResolver();

        self::assertNull($resolver->resolveForOrder(new Order()));
        self::assertNull($resolver->resolveForCustomer(new Customer()));
    }

    private function customer(int $id): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);

        return $customer;
    }
}
