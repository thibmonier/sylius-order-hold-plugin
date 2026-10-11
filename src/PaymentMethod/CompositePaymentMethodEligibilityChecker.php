<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/** Asks every tagged checker; the first refusal wins. */
final class CompositePaymentMethodEligibilityChecker implements PaymentMethodEligibilityCheckerInterface
{
    /** @param iterable<PaymentMethodEligibilityCheckerInterface> $checkers */
    public function __construct(private readonly iterable $checkers)
    {
    }

    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
    {
        foreach ($this->checkers as $checker) {
            $ineligibility = $checker->getIneligibility($paymentMethod, $payment);
            if (null !== $ineligibility) {
                return $ineligibility;
            }
        }

        return null;
    }
}
