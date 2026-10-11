<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
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

    private function login(CustomerInterface $customer): void
    {
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->entityManager()->flush();
        $this->client->loginUser($user, 'shop');
    }
}
