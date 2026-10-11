<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\Validator;

use Sylius\Bundle\ApiBundle\Command\Checkout\CompleteOrder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;

final class EligiblePaymentMethodsValidator extends ConstraintValidator
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EligiblePaymentMethods) {
            throw new UnexpectedTypeException($constraint, EligiblePaymentMethods::class);
        }

        // findOneByTokenValue() excludes carts.
        $order = $value instanceof CompleteOrder ? $this->orderRepository->findOneBy(['tokenValue' => $value->orderTokenValue]) : $value;
        if (!$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getPayments() as $payment) {
            if (!in_array($payment->getState(), [PaymentInterface::STATE_CART, PaymentInterface::STATE_NEW], true)) {
                continue;
            }

            $paymentMethod = $payment->getMethod();
            if (!$paymentMethod instanceof PaymentMethodInterface) {
                continue;
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
    }
}
