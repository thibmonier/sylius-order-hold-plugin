<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Tmonier\SyliusOrderHoldPlugin\Unit\PaymentMethod\PaymentMethodTestTrait;
use Tmonier\SyliusOrderHoldPlugin\EventListener\PaymentEntryBlockingListener;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\CompositePaymentEntryBlocker;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;

final class PaymentEntryBlockingListenerTest extends TestCase
{
    use PaymentMethodTestTrait;

    public function testItRedirectsABlockedOrderToTheThankYouPageWithAFlash(): void
    {
        $order = $this->order(42);
        $event = $this->event('sylius_shop_order_pay', 'TOKEN');

        $this->listener($order, new PaymentEntryBlock('app.awaiting_approval', ['%number%' => '000042']))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/en_US/thank-you', $response->getTargetUrl());
        $session = $event->getRequest()->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(42, $session->get('sylius_order_id'));
        self::assertSame([['message' => 'app.awaiting_approval', 'parameters' => ['%number%' => '000042']]], $session->getFlashBag()->get('info'));
    }

    public function testItLetsAnOrderWithoutBlockBePaid(): void
    {
        $event = $this->event('sylius_shop_order_pay', 'TOKEN');

        $this->listener($this->order(42), null)($event);

        self::assertNull($event->getResponse());
    }

    public function testItIgnoresOtherRoutesUnknownOrdersAndSubRequests(): void
    {
        $listener = $this->listener($this->order(42), new PaymentEntryBlock());

        $otherRoute = $this->event('sylius_shop_order_show', 'TOKEN');
        $listener($otherRoute);
        self::assertNull($otherRoute->getResponse());

        $subRequest = $this->event('sylius_shop_order_pay', 'TOKEN', HttpKernelInterface::SUB_REQUEST);
        $listener($subRequest);
        self::assertNull($subRequest->getResponse());

        $unknown = $this->event('sylius_shop_order_pay', 'UNKNOWN');
        $this->listener(null, new PaymentEntryBlock())($unknown);
        self::assertNull($unknown->getResponse());
    }

    public function testTheCompositeBlockerReturnsTheFirstBlock(): void
    {
        $first = new PaymentEntryBlock('first');
        $blocker = new CompositePaymentEntryBlocker([$this->blocker(null), $this->blocker($first), $this->blocker(new PaymentEntryBlock('second'))]);

        self::assertSame($first, $blocker->getBlock(new Order()));
        self::assertNull((new CompositePaymentEntryBlocker([]))->getBlock(new Order()));
        self::assertSame(PaymentEntryBlock::DEFAULT_MESSAGE, (new PaymentEntryBlock())->getMessage());
    }

    public function testARefusedMethodSendsTheCustomerBackToTheOrderPage(): void
    {
        $order = new Order();
        $order->setTokenValue('TOKEN');
        $order->setLocaleCode('en_US');
        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);
        $payment->setMethod(self::method('credit'));
        $order->addPayment($payment);
        $event = $this->event('sylius_shop_order_pay', 'TOKEN');

        $this->listener($order, null, ['credit'])($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/en_US/order/TOKEN', $response->getTargetUrl());
        $session = $event->getRequest()->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame([['message' => 'tmonier_order_hold.payment_method.ineligible', 'parameters' => ['%paymentMethodName%' => 'Credit']]], $session->getFlashBag()->get('error'));
    }

    public function testThePaymentRequestPayRouteChecksThePaymentRequestMethod(): void
    {
        $order = new Order();
        $order->setTokenValue('TOKEN');
        $order->setLocaleCode('en_US');
        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);
        $payment->setMethod(self::method('bank'));
        $order->addPayment($payment);
        $paymentRequest = new PaymentRequest($payment, self::method('credit'));

        $refused = $this->event('sylius_shop_payment_request_pay', 'HASH', attribute: 'hash');
        $this->listener($order, null, ['credit'], $paymentRequest)($refused);
        self::assertSame('/en_US/order/TOKEN', $this->redirectTarget($refused));

        $blocked = $this->event('sylius_shop_payment_request_pay', 'HASH', attribute: 'hash');
        $this->listener($order, new PaymentEntryBlock(), [], $paymentRequest)($blocked);
        self::assertSame('/en_US/thank-you', $this->redirectTarget($blocked));

        $accepted = $this->event('sylius_shop_payment_request_pay', 'HASH', attribute: 'hash');
        $this->listener($order, null, [], $paymentRequest)($accepted);
        self::assertNull($accepted->getResponse());
    }

    private function redirectTarget(RequestEvent $event): string
    {
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response->getTargetUrl();
    }

    /** @param list<string> $refusedCodes */
    private function listener(?OrderInterface $order, ?PaymentEntryBlock $block, array $refusedCodes = [], ?PaymentRequest $paymentRequest = null): PaymentEntryBlockingListener
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('findOneByTokenValue')->willReturnCallback(static fn (string $token): ?OrderInterface => 'TOKEN' === $token ? $order : null);
        $paymentRequests = $this->createMock(PaymentRequestRepositoryInterface::class);
        $paymentRequests->method('find')->willReturnCallback(static fn (mixed $hash): ?PaymentRequest => 'HASH' === $hash ? $paymentRequest : null);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => match ($route) {
            'sylius_shop_order_thank_you' => '/en_US/thank-you',
            'sylius_shop_order_show' => sprintf('/%s/order/%s', is_string($parameters['_locale'] ?? null) ? $parameters['_locale'] : '', is_string($parameters['tokenValue'] ?? null) ? $parameters['tokenValue'] : ''),
            default => '/unexpected',
        });

        return new PaymentEntryBlockingListener($repository, $paymentRequests, $this->blocker($block), self::refusing($refusedCodes), $urlGenerator);
    }

    private function blocker(?PaymentEntryBlock $block): PaymentEntryBlockerInterface
    {
        return new class($block) implements PaymentEntryBlockerInterface {
            public function __construct(private readonly ?PaymentEntryBlock $block)
            {
            }

            public function getBlock(OrderInterface $order): ?PaymentEntryBlock
            {
                return $this->block;
            }
        };
    }

    private function order(int $id): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn($id);
        $order->method('getLocaleCode')->willReturn('en_US');

        return $order;
    }

    private function event(string $route, string $token, int $type = HttpKernelInterface::MAIN_REQUEST, string $attribute = 'tokenValue'): RequestEvent
    {
        $request = new Request(attributes: ['_route' => $route, $attribute => $token]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }
}
