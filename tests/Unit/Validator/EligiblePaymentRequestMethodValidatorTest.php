<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\Validator;

use Sylius\Bundle\ApiBundle\Command\Payment\AddPaymentRequest;
use Sylius\Bundle\ApiBundle\Command\Payment\UpdatePaymentRequest;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod\PaymentMethodTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\Validator\EligiblePaymentRequestMethod;
use Tmonier\SyliusOrderHoldPlugin\Validator\EligiblePaymentRequestMethodValidator;

/** @extends ConstraintValidatorTestCase<EligiblePaymentRequestMethodValidator> */
final class EligiblePaymentRequestMethodValidatorTest extends ConstraintValidatorTestCase
{
    use PaymentMethodTestTrait;

    public function testARefusedMethodCannotBeUsedForAPaymentRequest(): void
    {
        $this->validator->validate(new AddPaymentRequest('TOKEN', 7, 'credit'), new EligiblePaymentRequestMethod());

        $this->buildViolation('tmonier_order_hold.payment_method.ineligible.credit_hold')
            ->setParameter('%paymentMethodName%', 'Credit')
            ->setCode('credit_hold')
            ->assertRaised()
        ;
    }

    public function testAnEligibleMethodIsAccepted(): void
    {
        $this->validator->validate(new AddPaymentRequest('TOKEN', 7, 'bank'), new EligiblePaymentRequestMethod());

        $this->assertNoViolation();
    }

    public function testAnUpdateChecksTheMethodOfThePaymentRequest(): void
    {
        $this->validator->validate(new UpdatePaymentRequest('HASH'), new EligiblePaymentRequestMethod());

        self::assertCount(1, $this->context->getViolations());
    }

    public function testUnknownPaymentsAndMethodsAreLeftToTheCoreValidation(): void
    {
        $this->validator->validate(new AddPaymentRequest('UNKNOWN', 7, 'credit'), new EligiblePaymentRequestMethod());
        $this->validator->validate(new AddPaymentRequest('TOKEN', 7, 'unknown'), new EligiblePaymentRequestMethod());
        $this->validator->validate(new UpdatePaymentRequest('UNKNOWN'), new EligiblePaymentRequestMethod());

        $this->assertNoViolation();
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $payment = new Payment();
        $payments = $this->createMock(PaymentRepositoryInterface::class);
        $payments->method('findOneByOrderToken')->willReturnCallback(static fn (mixed $id, string $token): ?PaymentInterface => 'TOKEN' === $token ? $payment : null);
        $methods = $this->createMock(PaymentMethodRepositoryInterface::class);
        $methods->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?PaymentMethodInterface => in_array($criteria['code'] ?? null, ['bank', 'credit'], true) ? self::method((string) $criteria['code']) : null);
        $paymentRequest = new PaymentRequest($payment, self::method('credit'));
        $paymentRequests = $this->createMock(PaymentRequestRepositoryInterface::class);
        $paymentRequests->method('find')->willReturnCallback(static fn (mixed $hash): ?PaymentRequest => 'HASH' === $hash ? $paymentRequest : null);

        return new EligiblePaymentRequestMethodValidator($payments, $methods, $paymentRequests, self::refusing(['credit'], IneligibilityReason::CreditHold));
    }
}
