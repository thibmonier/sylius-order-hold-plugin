<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Payment\Provider\OrderPaymentProviderInterface;
use Sylius\Component\Payment\Exception\UnresolvedDefaultPaymentMethodException;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\DefaultPaymentMethodResolverInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibleOrderPaymentProvider;

final class EligibleOrderPaymentProviderTest extends TestCase
{
    use PaymentMethodTestTrait;

    public function testACopiedRefusedMethodIsReplacedByTheEligibleDefault(): void
    {
        $card = self::method('card');
        $provider = new EligibleOrderPaymentProvider($this->provider(self::method('credit')), $this->defaultResolver($card), self::refusing(['credit']));

        self::assertSame($card, $provider->provideOrderPayment(new Order(), PaymentInterface::STATE_NEW)?->getMethod());
    }

    public function testAnEligibleMethodIsKept(): void
    {
        $bank = self::method('bank');
        $provider = new EligibleOrderPaymentProvider($this->provider($bank), $this->defaultResolver(self::method('card')), self::refusing(['credit']));

        self::assertSame($bank, $provider->provideOrderPayment(new Order(), PaymentInterface::STATE_NEW)?->getMethod());
    }

    public function testTheRefusedMethodIsKeptWhenNoMethodIsEligible(): void
    {
        $credit = self::method('credit');
        $provider = new EligibleOrderPaymentProvider($this->provider($credit), $this->defaultResolver(null), self::refusing(['credit']));

        self::assertSame($credit, $provider->provideOrderPayment(new Order(), PaymentInterface::STATE_NEW)?->getMethod());
    }

    private function provider(PaymentMethodInterface $method): OrderPaymentProviderInterface
    {
        return new class($method) implements OrderPaymentProviderInterface {
            public function __construct(private readonly PaymentMethodInterface $method)
            {
            }

            public function provideOrderPayment(OrderInterface $order, string $targetState): PaymentInterface
            {
                $payment = new Payment();
                $payment->setMethod($this->method);

                return $payment;
            }
        };
    }

    private function defaultResolver(?PaymentMethodInterface $default): DefaultPaymentMethodResolverInterface
    {
        return new class($default) implements DefaultPaymentMethodResolverInterface {
            public function __construct(private readonly ?PaymentMethodInterface $default)
            {
            }

            public function getDefaultPaymentMethod(BasePaymentInterface $payment): PaymentMethodInterface
            {
                return $this->default ?? throw new UnresolvedDefaultPaymentMethodException();
            }
        };
    }
}
