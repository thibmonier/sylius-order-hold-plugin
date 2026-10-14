<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Functional;

use Payum\Core\Payum;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;

final class ShopOrderHoldTest extends WebTestCase
{
    use OrderHoldTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testTheCheckoutOnlyOffersEligibleMethods(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $refused = $this->paymentMethod('Trade credit');
        $this->holds()->refuseMethod($refused);
        $customer = $this->customer(true);
        $this->order('shipping_selected', null, $customer);
        $this->login($customer);

        $this->client->request('GET', sprintf('/%s/checkout/select-payment', $this->localeCode()));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $eligible->getName(), $html);
        self::assertStringNotContainsString((string) $refused->getName(), $html);
    }

    public function testTheCheckoutCannotBeCompletedWithAMethodRefusedSinceItWasSelected(): void
    {
        $refused = $this->paymentMethod('Trade credit');
        $customer = $this->customer(true);
        $order = $this->order('payment_selected', $refused, $customer);
        $this->holds()->refuseMethod($refused, IneligibilityReason::CreditLimitExceeded);
        $this->login($customer);

        $crawler = $this->client->request('GET', sprintf('/%s/checkout/complete', $this->localeCode()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/checkout/complete"]')->form());

        self::assertStringContainsString(
            sprintf('The order total exceeds the credit available for the payment method %s.', (string) $refused->getName()),
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertSame('payment_selected', $this->reload($order)->getCheckoutState());
    }

    public function testTheOrderPageOnlyOffersEligibleMethodsAfterCheckout(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $other = $this->paymentMethod('Cash');
        $refused = $this->paymentMethod('Trade credit');
        $this->holds()->refuseMethod($refused);
        $order = $this->order('completed', $eligible);

        $this->client->request('GET', sprintf('/%s/order/%s', $this->localeCode(), (string) $order->getTokenValue()));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $other->getName(), $html);
        self::assertStringNotContainsString((string) $refused->getName(), $html);
    }

    public function testABlockedOrderCannotBePaid(): void
    {
        $order = $this->order('completed', $this->paymentMethod('Bank transfer'));
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', sprintf('/%s/order/%s/pay', $this->localeCode(), (string) $order->getTokenValue()));

        self::assertResponseRedirects(sprintf('/%s/order/thank-you', $this->localeCode()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('It cannot be paid for the moment', (string) $this->client->getResponse()->getContent());
    }

    public function testAnOrderWithoutBlockGoesThroughTheRegularPayment(): void
    {
        $order = $this->order('completed', $this->paymentMethod('Bank transfer'));

        $this->client->request('GET', sprintf('/%s/order/%s/pay', $this->localeCode(), (string) $order->getTokenValue()));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringNotContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirects();
        $this->client->followRedirect();
        self::assertStringNotContainsString('It cannot be paid for the moment', (string) $this->client->getResponse()->getContent());
    }

    public function testThePayRouteSendsBackToTheOrderPageWhenTheMethodWasRefusedSinceCheckout(): void
    {
        $refused = $this->paymentMethod('Trade credit');
        $order = $this->order('completed', $refused);
        $this->holds()->refuseMethod($refused);

        $this->client->request('GET', sprintf('/%s/order/%s/pay', $this->localeCode(), (string) $order->getTokenValue()));

        self::assertResponseRedirects(sprintf('/%s/order/%s', $this->localeCode(), (string) $order->getTokenValue()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            sprintf('The payment method %s is no longer available for this order.', (string) $refused->getName()),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testAPaymentRequestCreatedBeforeTheBlockCannotBePaid(): void
    {
        $method = $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $method);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        /** @var PaymentRequestFactoryInterface<PaymentRequestInterface> $factory */
        $factory = $this->service('sylius.factory.payment_request');
        $paymentRequest = $factory->create($payment, $method);
        $this->entityManager()->persist($paymentRequest);
        $this->entityManager()->flush();
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', sprintf('/%s/payment-request/pay/%s', $this->localeCode(), (string) $paymentRequest->getId()));

        self::assertResponseRedirects(sprintf('/%s/order/thank-you', $this->localeCode()));
    }

    public function testAPayumTokenCreatedBeforeTheBlockCannotBeUsed(): void
    {
        $method = $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $method);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $payum = $this->service('payum');
        self::assertInstanceOf(Payum::class, $payum);
        $token = $payum->getTokenFactory()->createCaptureToken((string) $method->getGatewayConfig()?->getGatewayName(), $payment, 'sylius_shop_order_after_pay', ['_locale' => $this->localeCode()]);
        $this->entityManager()->flush();
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', (string) parse_url($token->getTargetUrl(), \PHP_URL_PATH));

        self::assertResponseRedirects(sprintf('/%s/order/thank-you', $this->localeCode()));
    }

    public function testThePaymentCreatedAfterAFailedOneDoesNotKeepARefusedMethod(): void
    {
        $refused = $this->paymentMethod('Trade credit');
        $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $refused);
        $this->holds()->refuseMethod($refused);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
        $this->entityManager()->flush();

        $newPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($newPayment);
        self::assertNotSame($payment, $newPayment);
        self::assertNotSame($refused, $newPayment->getMethod());
    }

    public function testAGatewayReturnIsNeverIntercepted(): void
    {
        $method = $this->paymentMethod('Card gateway');
        $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $method);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $payum = $this->service('payum');
        self::assertInstanceOf(Payum::class, $payum);
        // Offsite gateways send the customer back to the capture URL.
        $token = $payum->getTokenFactory()->createCaptureToken((string) $method->getGatewayConfig()?->getGatewayName(), $payment, 'sylius_shop_order_after_pay', ['_locale' => $this->localeCode()]);
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS);
        $this->entityManager()->flush();
        $this->holds()->refuseMethod($method);
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', (string) parse_url($token->getTargetUrl(), \PHP_URL_PATH));

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/order/' . $order->getTokenValue(), $location);
        self::assertStringNotContainsString('/order/thank-you', $location);
    }

    public function testAReopenedTokenOfAPaymentStartedBeforeTheBlockIsIntercepted(): void
    {
        $method = $this->paymentMethod('Card gateway');
        $order = $this->order('completed', $method);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $payment->setDetails(['gateway_token' => 'EC-123']);
        $payum = $this->service('payum');
        self::assertInstanceOf(Payum::class, $payum);
        $token = $payum->getTokenFactory()->createCaptureToken((string) $method->getGatewayConfig()?->getGatewayName(), $payment, 'sylius_shop_order_after_pay', ['_locale' => $this->localeCode()]);
        $this->entityManager()->flush();
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', (string) parse_url($token->getTargetUrl(), \PHP_URL_PATH));

        // The payment is still new: the block wins over the gateway details.
        self::assertResponseRedirects(sprintf('/%s/order/thank-you', $this->localeCode()));
    }

    public function testRedirectsUseTheRequestLocaleWhenTheOrderLocaleLeftTheChannel(): void
    {
        $order = $this->order('completed', $this->paymentMethod('Bank transfer'));
        $order->setLocaleCode('xx_XX');
        $this->entityManager()->flush();
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', sprintf('/%s/order/%s/pay', $this->localeCode(), (string) $order->getTokenValue()));

        self::assertResponseRedirects(sprintf('/%s/order/thank-you', $this->localeCode()));
        $this->client->followRedirect();
        self::assertStringContainsString('It cannot be paid for the moment', (string) $this->client->getResponse()->getContent());
    }

    public function testRedirectsUseTheOrderLocaleWhenTheChannelOffersIt(): void
    {
        $channel = $this->channel();
        $locale = $this->entityManager()->getRepository(LocaleInterface::class)->findOneBy(['code' => 'fr_FR']);
        if (!$locale instanceof LocaleInterface) {
            /** @var FactoryInterface<LocaleInterface> $localeFactory */
            $localeFactory = $this->service('sylius.factory.locale');
            $locale = $localeFactory->createNew();
            $locale->setCode('fr_FR');
            $this->entityManager()->persist($locale);
        }
        $channel->addLocale($locale);
        $method = $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $method);
        $order->setLocaleCode('fr_FR');
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $payum = $this->service('payum');
        self::assertInstanceOf(Payum::class, $payum);
        // Payum routes have no locale in their path.
        $token = $payum->getTokenFactory()->createCaptureToken((string) $method->getGatewayConfig()?->getGatewayName(), $payment, 'sylius_shop_order_after_pay', ['_locale' => $this->localeCode()]);
        $this->entityManager()->flush();
        $this->holds()->blockPaymentEntry($order);

        $this->client->request('GET', (string) parse_url($token->getTargetUrl(), \PHP_URL_PATH));

        self::assertResponseRedirects('/fr_FR/order/thank-you');
    }

    private function login(CustomerInterface $customer): void
    {
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->entityManager()->flush();
        $this->client->loginUser($user, 'shop');
    }
}
