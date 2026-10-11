<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\Validator;

use Sylius\Bundle\ApiBundle\Command\Payment\AddPaymentRequest;
use Sylius\Bundle\ApiBundle\Command\Payment\UpdatePaymentRequest;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\Validator\PaymentEntryAllowed;
use Tmonier\SyliusOrderHoldPlugin\Validator\PaymentEntryAllowedValidator;

/** @extends ConstraintValidatorTestCase<PaymentEntryAllowedValidator> */
final class PaymentEntryAllowedValidatorTest extends ConstraintValidatorTestCase
{
    private Order $blockedOrder;

    private Order $freeOrder;

    protected function setUp(): void
    {
        $this->blockedOrder = new Order();
        $this->blockedOrder->setNumber('BLOCKED');
        $this->freeOrder = new Order();
        $this->freeOrder->setNumber('FREE');

        parent::setUp();
    }

    public function testAPaymentRequestOfABlockedOrderIsRefused(): void
    {
        $this->validator->validate(new AddPaymentRequest('BLOCKED', 1, 'bank'), new PaymentEntryAllowed());

        $this->buildViolation('tmonier_order_hold.payment_entry.blocked')->assertRaised();
    }

    public function testAPaymentRequestOfAnotherOrderIsAccepted(): void
    {
        $this->validator->validate(new AddPaymentRequest('FREE', 1, 'bank'), new PaymentEntryAllowed());

        $this->assertNoViolation();
    }

    public function testAnUpdateIsCheckedThroughThePaymentRequestOrder(): void
    {
        $this->validator->validate(new UpdatePaymentRequest('HASH'), new PaymentEntryAllowed());

        self::assertCount(1, $this->context->getViolations());
    }

    public function testUnknownOrdersAndOtherValuesAreIgnored(): void
    {
        $this->validator->validate(new AddPaymentRequest('UNKNOWN', 1, 'bank'), new PaymentEntryAllowed());
        $this->validator->validate(new UpdatePaymentRequest('UNKNOWN'), new PaymentEntryAllowed());
        $this->validator->validate(new \stdClass(), new PaymentEntryAllowed());

        $this->assertNoViolation();
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findOneBy')->willReturnCallback(fn (array $criteria): ?OrderInterface => match ($criteria['tokenValue']) {
            'BLOCKED' => $this->blockedOrder,
            'FREE' => $this->freeOrder,
            default => null,
        });

        $payment = new Payment();
        $payment->setOrder($this->blockedOrder);
        $paymentRequest = new PaymentRequest($payment, new PaymentMethod());
        $paymentRequests = $this->createMock(PaymentRequestRepositoryInterface::class);
        $paymentRequests->method('find')->willReturnCallback(static fn (mixed $hash): ?PaymentRequest => 'HASH' === $hash ? $paymentRequest : null);

        $blocker = new class() implements PaymentEntryBlockerInterface {
            public function getBlock(OrderInterface $order): ?PaymentEntryBlock
            {
                return 'BLOCKED' === $order->getNumber() ? new PaymentEntryBlock() : null;
            }
        };

        return new PaymentEntryAllowedValidator($orders, $paymentRequests, $blocker);
    }
}
