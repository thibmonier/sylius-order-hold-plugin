<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\CompositePaymentMethodEligibilityChecker;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligiblePaymentMethodsResolver;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;

final class EligiblePaymentMethodsResolverTest extends TestCase
{
    use PaymentMethodTestTrait;

    public function testItRemovesTheRefusedMethodsAndKeepsTheOrder(): void
    {
        $bank = self::method('bank');
        $credit = self::method('credit');
        $card = self::method('card');
        $resolver = new EligiblePaymentMethodsResolver(
            self::resolverReturning([$bank, $credit, $card]),
            new CompositePaymentMethodEligibilityChecker([self::refusing(['credit'])]),
        );

        self::assertSame([$bank, $card], $resolver->getSupportedMethods(self::payment()));
        self::assertTrue($resolver->supports(self::payment()));
    }

    public function testItKeepsEveryMethodWithoutChecker(): void
    {
        $methods = [self::method('bank'), self::method('card')];
        $resolver = new EligiblePaymentMethodsResolver(self::resolverReturning($methods, false), new CompositePaymentMethodEligibilityChecker([]));

        self::assertSame($methods, $resolver->getSupportedMethods(self::payment()));
        self::assertFalse($resolver->supports(self::payment()));
    }

    public function testTheFirstRefusalWins(): void
    {
        $checker = new CompositePaymentMethodEligibilityChecker([
            self::refusing(['other']),
            self::refusing(['credit'], IneligibilityReason::CreditHold),
            self::refusing(['credit'], IneligibilityReason::Overdue),
        ]);

        self::assertNull($checker->getIneligibility(self::method('bank'), self::payment()));
        self::assertSame(IneligibilityReason::CreditHold, $checker->getIneligibility(self::method('credit'), self::payment())?->getReason());
    }
}
