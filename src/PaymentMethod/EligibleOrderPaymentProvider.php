<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Payment\Provider\OrderPaymentProviderInterface;
use Sylius\Component\Payment\Exception\UnresolvedDefaultPaymentMethodException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\DefaultPaymentMethodResolverInterface;

/**
 * Decorates `sylius.provider.payment.order`. When a payment failed or was cancelled, the core
 * provider copies its method into the new payment, after (and instead of) the default payment
 * method resolver: a method refused since then is replaced by the eligible default. When no
 * method is eligible, the copied one is kept and the pay routes send the customer to the order
 * page.
 */
final readonly class EligibleOrderPaymentProvider implements OrderPaymentProviderInterface
{
    public function __construct(
        private OrderPaymentProviderInterface $decorated,
        private DefaultPaymentMethodResolverInterface $defaultPaymentMethodResolver,
        private PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    public function provideOrderPayment(OrderInterface $order, string $targetState): ?PaymentInterface
    {
        $payment = $this->decorated->provideOrderPayment($order, $targetState);
        $paymentMethod = $payment?->getMethod();
        if (
            !$payment instanceof PaymentInterface ||
            !$paymentMethod instanceof PaymentMethodInterface ||
            !$this->eligibilityChecker->getIneligibility($paymentMethod, $payment) instanceof \Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility
        ) {
            return $payment;
        }

        try {
            // Already set by the core provider; the payment is added to the order by the processor.
            $payment->setOrder($order);
            $payment->setMethod($this->defaultPaymentMethodResolver->getDefaultPaymentMethod($payment));
        } catch (UnresolvedDefaultPaymentMethodException) {
        }

        return $payment;
    }
}
