<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\UnpaidOrder;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHold;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHoldResolver;

final class UnpaidOrderHoldResolverTest extends TestCase
{
    public function testAnOrderWithoutHoldIsExpired(): void
    {
        $resolver = new UnpaidOrderHoldResolver([$this->exclusion(null)]);

        self::assertNull($resolver->resolve(new Order()));
        self::assertTrue($resolver->isExpired(new Order(), new \DateTimeImmutable()));
    }

    public function testAnIndefiniteHoldWinsOverDatedHolds(): void
    {
        $resolver = new UnpaidOrderHoldResolver([
            $this->exclusion(UnpaidOrderHold::since(new \DateTimeImmutable('-30 days'), 'approval')),
            $this->exclusion(UnpaidOrderHold::indefinitely('credit')),
            $this->exclusion(UnpaidOrderHold::since(new \DateTimeImmutable('-1 day'), 'other')),
        ]);

        $hold = $resolver->resolve(new Order());

        self::assertNotNull($hold);
        self::assertTrue($hold->isIndefinite());
        self::assertSame('credit', $hold->getSource());
        self::assertFalse($resolver->isExpired(new Order(), new \DateTimeImmutable('+1 year')));
    }

    public function testTheLatestExpirationStartWins(): void
    {
        $resolver = new UnpaidOrderHoldResolver([
            $this->exclusion(UnpaidOrderHold::since(new \DateTimeImmutable('2026-01-10'), 'early')),
            $this->exclusion(UnpaidOrderHold::since(new \DateTimeImmutable('2026-03-01'), 'late')),
            $this->exclusion(null),
        ]);

        $hold = $resolver->resolve(new Order());

        self::assertSame('late', $hold?->getSource());
        self::assertSame('2026-03-01', $hold->getExpirationStart()?->format('Y-m-d'));
        self::assertTrue($resolver->isExpired(new Order(), new \DateTimeImmutable('2026-03-02')));
        self::assertFalse($resolver->isExpired(new Order(), new \DateTimeImmutable('2026-02-15')));
    }

    public function testHoldsAcceptMutableDates(): void
    {
        $hold = UnpaidOrderHold::since(new \DateTime('2026-05-05 10:00:00'), 'approval');

        self::assertFalse($hold->isIndefinite());
        self::assertSame('2026-05-05 10:00:00', $hold->getExpirationStart()?->format('Y-m-d H:i:s'));
    }

    private function exclusion(?UnpaidOrderHold $hold): UnpaidOrderCancellationExclusionInterface
    {
        return new class($hold) implements UnpaidOrderCancellationExclusionInterface {
            public function __construct(private readonly ?UnpaidOrderHold $hold)
            {
            }

            public function getHold(OrderInterface $order): ?UnpaidOrderHold
            {
                return $this->hold;
            }
        };
    }
}
