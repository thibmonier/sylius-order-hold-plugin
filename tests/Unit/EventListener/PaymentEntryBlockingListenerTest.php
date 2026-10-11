<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusOrderHoldPlugin\EventListener\PaymentEntryBlockingListener;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\CompositePaymentEntryBlocker;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;

final class PaymentEntryBlockingListenerTest extends TestCase
{
    public function testItRedirectsABlockedOrderToTheThankYouPageWithAFlash(): void
    {
        $order = $this->order(42);
        $event = $this->event('sylius_shop_order_pay', 'TOKEN');

        $this->listener($order, new PaymentEntryBlock('app.awaiting_approval', ['%number%' => '000042']))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/thank-you', $response->getTargetUrl());
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

    private function listener(?OrderInterface $order, ?PaymentEntryBlock $block): PaymentEntryBlockingListener
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('findOneByTokenValue')->willReturnCallback(static fn (string $token): ?OrderInterface => 'TOKEN' === $token ? $order : null);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('sylius_shop_order_thank_you')->willReturn('/thank-you');

        return new PaymentEntryBlockingListener($repository, $this->blocker($block), $urlGenerator);
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

        return $order;
    }

    private function event(string $route, string $token, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = new Request(attributes: ['_route' => $route, 'tokenValue' => $token]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }
}
