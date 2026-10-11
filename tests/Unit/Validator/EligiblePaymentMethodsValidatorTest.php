<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\Validator;

use Sylius\Bundle\ApiBundle\Command\Checkout\CompleteOrder;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod\PaymentMethodTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\Validator\EligiblePaymentMethods;
use Tmonier\SyliusOrderHoldPlugin\Validator\EligiblePaymentMethodsValidator;

/** @extends ConstraintValidatorTestCase<EligiblePaymentMethodsValidator> */
final class EligiblePaymentMethodsValidatorTest extends ConstraintValidatorTestCase
{
    use PaymentMethodTestTrait;

    private ?OrderInterface $storedOrder = null;

    public function testAnOrderWithEligibleMethodsIsValid(): void
    {
        $this->validator->validate($this->order('bank'), new EligiblePaymentMethods());

        $this->assertNoViolation();
    }

    public function testAnOrderWithARefusedMethodIsInvalid(): void
    {
        $this->validator->validate($this->order('credit'), new EligiblePaymentMethods());

        $this->buildViolation('tmonier_order_hold.payment_method.ineligible.credit_hold')
            ->setParameter('%paymentMethodName%', 'Credit')
            ->setCode('credit_hold')
            ->assertRaised()
        ;
    }

    public function testPaymentsThatAreNotPendingAreIgnored(): void
    {
        $order = $this->order('credit');
        $payment = $order->getPayments()->first();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_COMPLETED);

        $this->validator->validate($order, new EligiblePaymentMethods());

        $this->assertNoViolation();
    }

    public function testTheApiCompleteOrderCommandIsCheckedThroughItsOrder(): void
    {
        $this->storedOrder = $this->order('credit');

        $this->validator->validate(new CompleteOrder('TOKEN'), new EligiblePaymentMethods());

        self::assertCount(1, $this->context->getViolations());
    }

    public function testAnUnknownOrderIsIgnored(): void
    {
        $this->validator->validate(new CompleteOrder('UNKNOWN'), new EligiblePaymentMethods());

        $this->assertNoViolation();
    }

    public function testItRejectsOtherConstraints(): void
    {
        $this->expectException(UnexpectedTypeException::class);
        $this->validator->validate($this->order('bank'), new NotNull());
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?OrderInterface => 'TOKEN' === $criteria['tokenValue'] ? $this->storedOrder : null);

        return new EligiblePaymentMethodsValidator($repository, self::refusing(['credit'], IneligibilityReason::CreditHold));
    }

    private function order(string $methodCode): Order
    {
        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);
        $payment->setMethod(self::method($methodCode));
        $order = new Order();
        $order->addPayment($payment);

        return $order;
    }
}
