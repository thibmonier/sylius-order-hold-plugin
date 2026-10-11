<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

use Sylius\Component\Core\Model\OrderInterface;

final class UnpaidOrderHoldResolver implements UnpaidOrderHoldResolverInterface
{
    /** @param iterable<UnpaidOrderCancellationExclusionInterface> $exclusions */
    public function __construct(private readonly iterable $exclusions)
    {
    }

    public function resolve(OrderInterface $order): ?UnpaidOrderHold
    {
        $resolved = null;
        foreach ($this->exclusions as $exclusion) {
            $hold = $exclusion->getHold($order);
            if (null === $hold) {
                continue;
            }
            if ($hold->isIndefinite()) {
                return $hold;
            }
            if (null === $resolved || $hold->getExpirationStart() > $resolved->getExpirationStart()) {
                $resolved = $hold;
            }
        }

        return $resolved;
    }

    public function isExpired(OrderInterface $order, \DateTimeInterface $terminalDate): bool
    {
        $hold = $this->resolve($order);
        if (null === $hold) {
            return true;
        }

        $expirationStart = $hold->getExpirationStart();

        return null !== $expirationStart && $expirationStart < $terminalDate;
    }
}
