<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Shop API `AddPaymentRequest` / `UpdatePaymentRequest` commands: the payment method of the
 * request is accepted by the eligibility checkers. The core handler loads any method by code.
 */
final class EligiblePaymentRequestMethod extends Constraint
{
    public function validatedBy(): string
    {
        return 'tmonier_order_hold.validator.eligible_payment_request_method';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
