<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\AccountOwner;

/**
 * Who an order or a customer is accountable to (credit line, approval rules): the customer
 * itself by default, a company when a company plugin decorates the resolver.
 */
final readonly class AccountOwner
{
    public const TYPE_CUSTOMER = 'customer';

    public function __construct(
        private string $type,
        private string $identifier,
        private object $subject,
    ) {
    }

    /** E.g. `customer`, `company`; stored next to the identifier by the plugins that persist an owner. */
    public function getType(): string
    {
        return $this->type;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /** The owner object itself (customer, company...). */
    public function getSubject(): object
    {
        return $this->subject;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->identifier === $other->identifier;
    }
}
