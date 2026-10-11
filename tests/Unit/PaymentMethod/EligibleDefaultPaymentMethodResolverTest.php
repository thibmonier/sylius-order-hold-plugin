<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Exception\UnresolvedDefaultPaymentMethodException;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\DefaultPaymentMethodResolverInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibleDefaultPaymentMethodResolver;

final class EligibleDefaultPaymentMethodResolverTest extends TestCase
{
    use PaymentMethodTestTrait;

    public function testItKeepsAnEligibleDefault(): void
    {
        $bank = self::method('bank');
        $resolver = new EligibleDefaultPaymentMethodResolver(
            $this->defaultResolver($bank),
            self::resolverReturning([self::method('card')]),
            self::refusing([]),
        );

        self::assertSame($bank, $resolver->getDefaultPaymentMethod(self::payment()));
    }

    public function testItFallsBackToTheFirstEligibleSupportedMethod(): void
    {
        $credit = self::method('credit');
        $card = self::method('card');
        $resolver = new EligibleDefaultPaymentMethodResolver(
            $this->defaultResolver($credit),
            self::resolverReturning([$credit, $card]),
            self::refusing(['credit']),
        );

        self::assertSame($card, $resolver->getDefaultPaymentMethod(self::payment()));
    }

    public function testItThrowsWhenNoMethodIsEligible(): void
    {
        $credit = self::method('credit');
        $resolver = new EligibleDefaultPaymentMethodResolver(
            $this->defaultResolver($credit),
            self::resolverReturning([$credit]),
            self::refusing(['credit']),
        );

        $this->expectException(UnresolvedDefaultPaymentMethodException::class);
        $resolver->getDefaultPaymentMethod(self::payment());
    }

    public function testItThrowsWhenThePaymentIsNotSupported(): void
    {
        $resolver = new EligibleDefaultPaymentMethodResolver(
            $this->defaultResolver(self::method('credit')),
            self::resolverReturning([self::method('card')], false),
            self::refusing(['credit']),
        );

        $this->expectException(UnresolvedDefaultPaymentMethodException::class);
        $resolver->getDefaultPaymentMethod(self::payment());
    }

    private function defaultResolver(PaymentMethodInterface $default): DefaultPaymentMethodResolverInterface
    {
        return new class($default) implements DefaultPaymentMethodResolverInterface {
            public function __construct(private readonly PaymentMethodInterface $default)
            {
            }

            public function getDefaultPaymentMethod(PaymentInterface $payment): PaymentMethodInterface
            {
                return $this->default;
            }
        };
    }
}
