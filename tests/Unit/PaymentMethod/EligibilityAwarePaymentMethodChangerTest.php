<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod;

use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ApiBundle\Changer\PaymentMethodChangerInterface;
use Sylius\Bundle\ApiBundle\Exception\PaymentMethodCannotBeChangedException;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibilityAwarePaymentMethodChanger;

final class EligibilityAwarePaymentMethodChangerTest extends TestCase
{
    use PaymentMethodTestTrait;

    public function testItRefusesAnIneligibleMethod(): void
    {
        $decorated = $this->createMock(PaymentMethodChangerInterface::class);
        $decorated->expects(self::never())->method('changePaymentMethod');

        $changer = new EligibilityAwarePaymentMethodChanger($decorated, $this->payments(), $this->methods(), self::refusing(['credit']));

        $this->expectException(PaymentMethodCannotBeChangedException::class);
        $changer->changePaymentMethod('credit', 12, new Order());
    }

    public function testItDelegatesAnEligibleMethod(): void
    {
        $order = new Order();
        $decorated = $this->createMock(PaymentMethodChangerInterface::class);
        $decorated->expects(self::once())->method('changePaymentMethod')->with('bank', 12, $order)->willReturn($order);

        $changer = new EligibilityAwarePaymentMethodChanger($decorated, $this->payments(), $this->methods(), self::refusing(['credit']));

        self::assertSame($order, $changer->changePaymentMethod('bank', 12, $order));
    }

    public function testItLetsTheDecoratedChangerReportUnknownMethodsAndPayments(): void
    {
        $order = new Order();
        $decorated = $this->createMock(PaymentMethodChangerInterface::class);
        $decorated->expects(self::once())->method('changePaymentMethod')->willReturn($order);
        $methods = $this->createMock(PaymentMethodRepositoryInterface::class);
        $methods->method('findOneBy')->willReturn(null);

        $changer = new EligibilityAwarePaymentMethodChanger($decorated, $this->payments(), $methods, self::refusing(['credit']));

        self::assertSame($order, $changer->changePaymentMethod('credit', 12, $order));
    }

    /** @return PaymentRepositoryInterface<PaymentInterface> */
    private function payments(): PaymentRepositoryInterface
    {
        $payments = $this->createMock(PaymentRepositoryInterface::class);
        $payments->method('findOneByOrderId')->willReturn(self::payment());

        return $payments;
    }

    /** @return PaymentMethodRepositoryInterface<PaymentMethodInterface> */
    private function methods(): PaymentMethodRepositoryInterface
    {
        $methods = $this->createMock(PaymentMethodRepositoryInterface::class);
        $methods->method('findOneBy')->willReturnCallback(static fn (array $criteria): PaymentMethodInterface => self::method(is_string($criteria['code'] ?? null) ? $criteria['code'] : ''));

        return $methods;
    }
}
