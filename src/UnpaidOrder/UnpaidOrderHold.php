<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

/**
 * Why an unpaid order must not be cancelled yet: either indefinitely (credit terms, pending
 * approval) or because its expiration period runs from a later date than checkout completion
 * (e.g. the approval date).
 */
final class UnpaidOrderHold
{
    private function __construct(
        private readonly ?\DateTimeImmutable $expirationStart,
        private readonly string $source,
    ) {
    }

    /** The order stays out of the unpaid-orders cancellation until the hold is lifted. */
    public static function indefinitely(string $source): self
    {
        return new self(null, $source);
    }

    /** The order is cancelled once the expiration period has elapsed since `$expirationStart`. */
    public static function since(\DateTimeInterface $expirationStart, string $source): self
    {
        return new self(\DateTimeImmutable::createFromInterface($expirationStart), $source);
    }

    public function isIndefinite(): bool
    {
        return null === $this->expirationStart;
    }

    public function getExpirationStart(): ?\DateTimeImmutable
    {
        return $this->expirationStart;
    }

    /** Free-form identifier of the plugin or rule behind the hold, used in logs. */
    public function getSource(): string
    {
        return $this->source;
    }
}
