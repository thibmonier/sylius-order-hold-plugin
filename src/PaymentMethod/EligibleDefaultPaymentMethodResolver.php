<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

use Sylius\Component\Payment\Exception\UnresolvedDefaultPaymentMethodException;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\DefaultPaymentMethodResolverInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/**
 * Decorates `sylius.resolver.payment_method.default`, which takes the first enabled method of
 * the channel without going through the payment methods resolver: when that method is refused,
 * the first method offered by the (filtered) payment methods resolver is used instead.
 */
final readonly class EligibleDefaultPaymentMethodResolver implements DefaultPaymentMethodResolverInterface
{
    public function __construct(
        private DefaultPaymentMethodResolverInterface $decorated,
        private PaymentMethodsResolverInterface $paymentMethodsResolver,
        private PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    /** @throws UnresolvedDefaultPaymentMethodException */
    public function getDefaultPaymentMethod(PaymentInterface $payment): PaymentMethodInterface
    {
        $default = $this->decorated->getDefaultPaymentMethod($payment);
        if (!$this->eligibilityChecker->getIneligibility($default, $payment) instanceof \Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility) {
            return $default;
        }

        if ($this->paymentMethodsResolver->supports($payment)) {
            foreach ($this->paymentMethodsResolver->getSupportedMethods($payment) as $paymentMethod) {
                if (!$this->eligibilityChecker->getIneligibility($paymentMethod, $payment) instanceof \Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility) {
                    return $paymentMethod;
                }
            }
        }

        throw new UnresolvedDefaultPaymentMethodException();
    }
}
