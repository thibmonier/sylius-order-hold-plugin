<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

/** Why a payment method is refused for a payment; each case has a default validator message. */
enum IneligibilityReason: string
{
    /** The method is not offered to this customer, customer group or account. */
    case Restricted = 'restricted';

    /** The order waits for an approval before it can be paid this way. */
    case AwaitingApproval = 'awaiting_approval';

    /** The order total exceeds the available credit. */
    case CreditLimitExceeded = 'credit_limit_exceeded';

    /** The account is on credit hold. */
    case CreditHold = 'credit_hold';

    /** The account has overdue invoices. */
    case Overdue = 'overdue';

    case Other = 'other';

    public function getDefaultMessage(): string
    {
        return 'tmonier_order_hold.payment_method.ineligible.' . $this->value;
    }
}
