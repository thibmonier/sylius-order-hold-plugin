<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\Validator;

use Sylius\Bundle\ApiBundle\Command\Payment\AddPaymentRequest;
use Sylius\Bundle\ApiBundle\Command\Payment\UpdatePaymentRequest;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface as BasePaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;

final class EligiblePaymentRequestMethodValidator extends ConstraintValidator
{
    /**
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EligiblePaymentRequestMethod) {
            throw new UnexpectedTypeException($constraint, EligiblePaymentRequestMethod::class);
        }

        [$payment, $paymentMethod] = match (true) {
            $value instanceof AddPaymentRequest => [
                $this->paymentRepository->findOneByOrderToken($value->paymentId, $value->orderTokenValue),
                $this->paymentMethodRepository->findOneBy(['code' => $value->paymentMethodCode]),
            ],
            $value instanceof UpdatePaymentRequest => $this->ofPaymentRequest($value->hash),
            default => [null, null],
        };
        if (!$payment instanceof BasePaymentInterface || !$paymentMethod instanceof BasePaymentMethodInterface) {
            return;
        }

        $ineligibility = $this->eligibilityChecker->getIneligibility($paymentMethod, $payment);
        if (null !== $ineligibility) {
            $this->context
                ->buildViolation($ineligibility->getMessage(), ['%paymentMethodName%' => (string) $paymentMethod->getName()] + $ineligibility->getParameters())
                ->setCode($ineligibility->getReason()->value)
                ->addViolation()
            ;
        }
    }

    /** @return array{0: ?BasePaymentInterface, 1: ?BasePaymentMethodInterface} */
    private function ofPaymentRequest(string $hash): array
    {
        $paymentRequest = $this->paymentRequestRepository->find($hash);

        return $paymentRequest instanceof PaymentRequestInterface ? [$paymentRequest->getPayment(), $paymentRequest->getMethod()] : [null, null];
    }
}
