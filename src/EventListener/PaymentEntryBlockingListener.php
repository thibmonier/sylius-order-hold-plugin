<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\EventListener;

use Payum\Core\Payum;
use Payum\Core\Security\TokenInterface;
use Payum\Core\Storage\IdentityInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;

/**
 * `kernel.request` listener on the shop routes that start a payment: the order pay route
 * (reached after checkout and from the order page through route options), the payment request
 * pay route and the Payum capture / authorize routes (tokens may have been created before the
 * order was blocked). The pay response providers have a different signature in Sylius 2.2 and
 * 2.3, so the request is intercepted before the controller.
 *
 * - Blocked order: redirect to the thank-you page (like a paid offline order) with a flash.
 * - Payment method refused since it was chosen (or copied from a failed payment), payment not
 *   started yet: redirect to the order page, whose form only offers eligible methods.
 * - Payum routes: only payments still `new` are intercepted (a blocked order wins, even with
 *   gateway details); payments the gateway is handling and notifications always pass.
 */
final class PaymentEntryBlockingListener
{
    public const ORDER_PAY_ROUTE = 'sylius_shop_order_pay';

    public const PAYMENT_REQUEST_PAY_ROUTE = 'sylius_shop_payment_request_pay';

    public const PAYUM_ROUTES = ['payum_capture_do', 'payum_capture_do_session', 'payum_authorize_do'];

    public const INELIGIBLE_METHOD_FLASH = 'tmonier_order_hold.payment_method.ineligible';

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentEntryBlockerInterface $paymentEntryBlocker,
        private readonly PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?Payum $payum = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        [$order, $payment, $paymentMethod] = $this->resolve($request);
        if (!$order instanceof OrderInterface) {
            return;
        }

        $block = $this->paymentEntryBlocker->getBlock($order);
        if (null !== $block) {
            if ($request->hasSession()) {
                $session = $request->getSession();
                // Read by the thank-you page, as the pay action does.
                $session->set('sylius_order_id', $order->getId());
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('info', ['message' => $block->getMessage(), 'parameters' => $block->getParameters()]);
                }
            }

            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('sylius_shop_order_thank_you', $this->localeParameters($request, $order))));

            return;
        }

        $payment ??= $order->getLastPayment(PaymentInterface::STATE_NEW);
        $paymentMethod ??= $payment?->getMethod();
        // Only a payment not started yet: a refused method must not strand a payment in progress.
        if (
            !$payment instanceof PaymentInterface ||
            PaymentInterface::STATE_NEW !== $payment->getState() ||
            !$paymentMethod instanceof PaymentMethodInterface ||
            null === $this->eligibilityChecker->getIneligibility($paymentMethod, $payment)
        ) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', ['message' => self::INELIGIBLE_METHOD_FLASH, 'parameters' => ['%paymentMethodName%' => (string) $paymentMethod->getName()]]);
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(
            'sylius_shop_order_show',
            ['tokenValue' => (string) $order->getTokenValue()] + $this->localeParameters($request, $order),
        )));
    }

    /** @return array{0: ?OrderInterface, 1: ?PaymentInterface, 2: ?PaymentMethodInterface} */
    private function resolve(Request $request): array
    {
        $route = $request->attributes->get('_route');

        if (self::ORDER_PAY_ROUTE === $route) {
            $tokenValue = $request->attributes->get('tokenValue');

            $order = is_string($tokenValue) ? $this->orderRepository->findOneByTokenValue($tokenValue) : null;

            return [$order instanceof OrderInterface ? $order : null, null, null];
        }

        if (self::PAYMENT_REQUEST_PAY_ROUTE === $route) {
            $hash = $request->attributes->get('hash');
            $paymentRequest = is_string($hash) ? $this->paymentRequestRepository->find($hash) : null;
            if (!$paymentRequest instanceof PaymentRequestInterface) {
                return [null, null, null];
            }
            $payment = $paymentRequest->getPayment();

            return $payment instanceof PaymentInterface ? [$payment->getOrder(), $payment, $paymentRequest->getMethod()] : [null, null, null];
        }

        if (in_array($route, self::PAYUM_ROUTES, true) && null !== $this->payum) {
            $payment = $this->payumPayment($request);
            // The capture / authorize URL is also the gateway's browser return URL (offsite
            // gateways). The block wins while the payment is still `new`, even when the gateway
            // already stored details (a token reopened after the block); a payment the gateway is
            // handling (processing, authorized, completed...) always passes.
            if (!$payment instanceof PaymentInterface || PaymentInterface::STATE_NEW !== $payment->getState()) {
                return [null, null, null];
            }

            return [$payment->getOrder(), $payment, null];
        }

        return [null, null, null];
    }

    /** The Sylius payment behind a Payum token, without invalidating it. */
    private function payumPayment(Request $request): ?PaymentInterface
    {
        $hash = $request->attributes->get('payum_token') ?? $request->query->get('payum_token') ?? $request->request->get('payum_token');
        if (null === $hash && $request->hasSession()) {
            $hash = $request->getSession()->get('payum_token');
        }
        if (!is_string($hash) || '' === $hash || null === $this->payum) {
            return null;
        }

        try {
            $token = $this->payum->getTokenStorage()->find($hash);
            if (!$token instanceof TokenInterface) {
                return null;
            }
            /** @var mixed $details */
            $details = $token->getDetails();
            if ($details instanceof IdentityInterface) {
                $details = $this->payum->getStorage($details->getClass())->find($details);
            }
        } catch (\Throwable) {
            // Unknown storage or malformed token: Payum reports it itself.
            return null;
        }

        return $details instanceof PaymentInterface ? $details : null;
    }

    /**
     * The locale of the request when the channel offers it (Payum routes have none), else the
     * order locale when the channel offers it, else the channel default locale.
     *
     * @return array<string, string>
     */
    private function localeParameters(Request $request, OrderInterface $order): array
    {
        $channel = $order->getChannel();
        $channelLocales = $channel instanceof ChannelInterface
            ? array_map(static fn (LocaleInterface $locale): ?string => $locale->getCode(), $channel->getLocales()->toArray())
            : [];
        $requestLocale = $request->attributes->get('_locale');

        $localeCode = match (true) {
            is_string($requestLocale) && in_array($requestLocale, $channelLocales, true) => $requestLocale,
            null !== $order->getLocaleCode() && in_array($order->getLocaleCode(), $channelLocales, true) => $order->getLocaleCode(),
            $channel instanceof ChannelInterface && null !== $channel->getDefaultLocale()?->getCode() => $channel->getDefaultLocale()->getCode(),
            [] === $channelLocales => $order->getLocaleCode(),
            default => null,
        };

        return null === $localeCode ? [] : ['_locale' => $localeCode];
    }
}
