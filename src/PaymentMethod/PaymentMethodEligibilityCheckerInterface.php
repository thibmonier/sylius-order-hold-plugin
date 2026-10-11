<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Refuses payment methods for a payment. Implementations are autoconfigured with the
 * `tmonier_order_hold.payment_method_eligibility_checker` tag and applied everywhere Sylius
 * offers or assigns a payment method: the composite payment methods resolver (shop checkout and
 * order page, API choices and validation), the default payment method resolver, the API payment
 * method changer and checkout completion (shop and API).
 *
 * Do not tag an extra `sylius.payment_method_resolver` to filter methods: the core composite
 * resolver returns the methods of the first resolver that supports the payment, so it would
 * replace the channel-based resolver instead of filtering it.
 */
interface PaymentMethodEligibilityCheckerInterface
{
    /** Null when the method is eligible as far as this checker is concerned. */
    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility;
}
