<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\Validator;

use Symfony\Component\Validator\Constraint;

/** Shop API `AddPaymentRequest` / `UpdatePaymentRequest` commands: the order is not blocked by a payment entry blocker. */
final class PaymentEntryAllowed extends Constraint
{
    public function validatedBy(): string
    {
        return 'tmonier_order_hold.validator.payment_entry_allowed';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
