<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

/** A refusal returned by a payment method eligibility checker. */
final class PaymentMethodIneligibility
{
    /**
     * @param string|null $message translation key (validators domain); the reason's default message when null
     * @param array<string, string|int|float> $parameters translation parameters, e.g. `%available%`
     */
    public function __construct(
        private readonly IneligibilityReason $reason,
        private readonly ?string $message = null,
        private readonly array $parameters = [],
    ) {
    }

    public function getReason(): IneligibilityReason
    {
        return $this->reason;
    }

    public function getMessage(): string
    {
        return $this->message ?? $this->reason->getDefaultMessage();
    }

    /** @return array<string, string|int|float> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
