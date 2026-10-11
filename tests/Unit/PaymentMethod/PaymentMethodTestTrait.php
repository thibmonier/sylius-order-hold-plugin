<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod;

use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility;

trait PaymentMethodTestTrait
{
    private static function method(string $code): PaymentMethod
    {
        $method = new PaymentMethod();
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName(ucfirst($code));

        return $method;
    }

    /** @param list<string> $refusedCodes */
    private static function refusing(array $refusedCodes, IneligibilityReason $reason = IneligibilityReason::Restricted): PaymentMethodEligibilityCheckerInterface
    {
        return new class($refusedCodes, $reason) implements PaymentMethodEligibilityCheckerInterface {
            /** @param list<string> $refusedCodes */
            public function __construct(private readonly array $refusedCodes, private readonly IneligibilityReason $reason)
            {
            }

            public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
            {
                return in_array($paymentMethod->getCode(), $this->refusedCodes, true) ? new PaymentMethodIneligibility($this->reason) : null;
            }
        };
    }

    /** @param list<PaymentMethodInterface> $methods */
    private static function resolverReturning(array $methods, bool $supports = true): PaymentMethodsResolverInterface
    {
        return new class($methods, $supports) implements PaymentMethodsResolverInterface {
            /** @param list<PaymentMethodInterface> $methods */
            public function __construct(private readonly array $methods, private readonly bool $supports)
            {
            }

            public function getSupportedMethods(PaymentInterface $subject): array
            {
                return $this->methods;
            }

            public function supports(PaymentInterface $subject): bool
            {
                return $this->supports;
            }
        };
    }

    private static function payment(): Payment
    {
        return new Payment();
    }
}
