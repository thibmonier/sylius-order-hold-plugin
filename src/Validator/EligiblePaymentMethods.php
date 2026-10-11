<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checkout completion (shop order, API `CompleteOrder` command): every payment of the order
 * uses a method accepted by the eligibility checkers. The core constraint only checks that the
 * method is enabled in the channel.
 */
final class EligiblePaymentMethods extends Constraint
{
    public function validatedBy(): string
    {
        return 'tmonier_order_hold.validator.eligible_payment_methods';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
