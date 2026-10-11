<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentEntry;

/** Why the customer cannot start paying an order (yet). */
final class PaymentEntryBlock
{
    public const DEFAULT_MESSAGE = 'tmonier_order_hold.payment_entry.blocked';

    /**
     * @param string $message translation key, looked up in the flashes domain (shop) and the validators domain (API)
     * @param array<string, string|int|float> $parameters
     */
    public function __construct(
        private readonly string $message = self::DEFAULT_MESSAGE,
        private readonly array $parameters = [],
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /** @return array<string, string|int|float> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
