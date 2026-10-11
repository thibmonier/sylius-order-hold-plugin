<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Integration;

use Sylius\Bundle\ApiBundle\Changer\PaymentMethodChangerInterface;
use Sylius\Bundle\ApiBundle\Exception\PaymentMethodCannotBeChangedException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusOrderHoldPlugin\Functional\OrderHoldTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibilityAwarePaymentMethodChanger;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligiblePaymentMethodsResolver;

/** The decorated payment method services, as wired in the application. */
final class DefaultPaymentMethodTest extends KernelTestCase
{
    use OrderHoldTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testTheOrderProcessorNeverAssignsARefusedDefaultMethod(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $refused = $this->paymentMethod('Trade credit');
        /** @var PaymentMethodRepositoryInterface<PaymentMethodInterface> $repository */
        $repository = $this->service('sylius.repository.payment_method');
        // The core default resolver takes the first enabled method of the channel, whatever it is.
        $firstOfChannel = $repository->findEnabledForChannel($this->channel())[0];
        self::assertInstanceOf(PaymentMethodInterface::class, $firstOfChannel);
        $this->holds()->refuseMethod($firstOfChannel);
        $this->holds()->refuseMethod($refused);

        $order = $this->order('shipping_selected');

        $method = $order->getLastPayment(PaymentInterface::STATE_CART)?->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        self::assertNotSame($firstOfChannel, $method);
        self::assertNotSame($refused, $method);
        self::assertNotNull($eligible->getId());
    }

    public function testTheCompositeResolverFiltersTheRefusedMethods(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $refused = $this->paymentMethod('Trade credit');
        $this->holds()->refuseMethod($refused);
        $order = $this->order('shipping_selected');
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);

        $resolver = $this->service('sylius.resolver.payment_methods');
        self::assertInstanceOf(EligiblePaymentMethodsResolver::class, $resolver);
        self::assertInstanceOf(EligiblePaymentMethodsResolver::class, $this->service(PaymentMethodsResolverInterface::class));
        $methods = $resolver->getSupportedMethods($payment);

        self::assertContains($eligible, $methods);
        self::assertNotContains($refused, $methods);
    }

    public function testTheApiChangerRefusesARefusedMethodAfterCheckout(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $refused = $this->paymentMethod('Trade credit');
        $this->holds()->refuseMethod($refused);
        $order = $this->order('completed', $eligible);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $changer = $this->service('sylius_api.changer.payment_method');
        self::assertInstanceOf(EligibilityAwarePaymentMethodChanger::class, $changer);
        self::assertInstanceOf(EligibilityAwarePaymentMethodChanger::class, $this->service(PaymentMethodChangerInterface::class));

        try {
            $changer->changePaymentMethod((string) $refused->getCode(), $payment->getId(), $order);
            self::fail('The refused method was accepted.');
        } catch (PaymentMethodCannotBeChangedException) {
            self::assertSame($eligible, $payment->getMethod());
        }

        $other = $this->paymentMethod('Cash');
        $changer->changePaymentMethod((string) $other->getCode(), $payment->getId(), $order);
        self::assertSame($other, $payment->getMethod());
    }
}
