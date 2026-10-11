<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/** Decorates the composite `sylius.resolver.payment_methods`: removes the refused methods. */
final class EligiblePaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    public function __construct(
        private readonly PaymentMethodsResolverInterface $decorated,
        private readonly PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    /** @return PaymentMethodInterface[] */
    public function getSupportedMethods(PaymentInterface $subject): array
    {
        return array_values(array_filter(
            $this->decorated->getSupportedMethods($subject),
            fn (PaymentMethodInterface $paymentMethod): bool => null === $this->eligibilityChecker->getIneligibility($paymentMethod, $subject),
        ));
    }

    public function supports(PaymentInterface $subject): bool
    {
        return $this->decorated->supports($subject);
    }
}
