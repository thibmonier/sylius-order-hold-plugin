<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\EventListener;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;

/**
 * `kernel.request` listener on the shop pay route: the checkout and the order page redirect to
 * it through a route option, and its pay response providers have a different signature in
 * Sylius 2.2 and 2.3, so the request is intercepted before the controller. A blocked order is
 * redirected to the thank-you page (like a paid offline order) with a flash message.
 */
final class PaymentEntryBlockingListener
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentEntryBlockerInterface $paymentEntryBlocker,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $payRoute = 'sylius_shop_order_pay',
        private readonly string $redirectRoute = 'sylius_shop_order_thank_you',
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $tokenValue = $request->attributes->get('tokenValue');
        if ($this->payRoute !== $request->attributes->get('_route') || !is_string($tokenValue)) {
            return;
        }

        $order = $this->orderRepository->findOneByTokenValue($tokenValue);
        if (!$order instanceof OrderInterface) {
            return;
        }

        $block = $this->paymentEntryBlocker->getBlock($order);
        if (null === $block) {
            return;
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            // Read by the thank-you page, as the pay action does.
            $session->set('sylius_order_id', $order->getId());
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('info', ['message' => $block->getMessage(), 'parameters' => $block->getParameters()]);
            }
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate($this->redirectRoute)));
    }
}
