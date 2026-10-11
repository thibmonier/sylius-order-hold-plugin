<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;

/** Shop API, guest orders (no authentication needed). */
final class ApiOrderHoldTest extends WebTestCase
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

    public function testThePaymentMethodsOfAPaymentExcludeRefusedMethods(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $refused = $this->paymentMethod('Trade credit');
        $this->holds()->refuseMethod($refused);
        $order = $this->order('shipping_selected');

        $data = $this->json('GET', sprintf('/api/v2/shop/orders/%s/payments/%s/methods', (string) $order->getTokenValue(), $this->paymentId($order)));

        self::assertResponseIsSuccessful();
        $members = $data['hydra:member'] ?? $data['member'] ?? null;
        self::assertIsArray($members);
        $codes = array_map(static fn (mixed $member): mixed => is_array($member) ? ($member['code'] ?? null) : null, $members);
        self::assertContains($eligible->getCode(), $codes);
        self::assertNotContains($refused->getCode(), $codes);
    }

    public function testARefusedMethodCannotBeChosenAfterCheckout(): void
    {
        $eligible = $this->paymentMethod('Bank transfer');
        $other = $this->paymentMethod('Cash');
        $refused = $this->paymentMethod('Trade credit');
        $this->holds()->refuseMethod($refused);
        $order = $this->order('completed', $eligible);
        $uri = sprintf('/api/v2/shop/orders/%s/payments/%s', (string) $order->getTokenValue(), $this->paymentId($order));

        $this->json('PATCH', $uri, ['paymentMethod' => '/api/v2/shop/payment-methods/' . $refused->getCode()]);
        self::assertResponseStatusCodeSame(422);

        $this->json('PATCH', $uri, ['paymentMethod' => '/api/v2/shop/payment-methods/' . $other->getCode()]);
        self::assertResponseIsSuccessful();
        self::assertSame($other->getCode(), $this->reload($order)->getLastPayment()?->getMethod()?->getCode());
    }

    public function testTheCheckoutCannotBeCompletedWithARefusedMethod(): void
    {
        $refused = $this->paymentMethod('Trade credit');
        $order = $this->order('payment_selected', $refused);
        $this->holds()->refuseMethod($refused, IneligibilityReason::AwaitingApproval);

        $data = $this->json('PATCH', sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()), []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            sprintf('The payment method %s cannot be used while the order is awaiting approval.', (string) $refused->getName()),
            (string) json_encode($data),
        );
        self::assertSame('payment_selected', $this->reload($order)->getCheckoutState());
    }

    public function testNoPaymentRequestCanBeCreatedForABlockedOrder(): void
    {
        $method = $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $method);
        $this->holds()->blockPaymentEntry($order);
        $uri = sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue());
        $body = ['paymentId' => $this->paymentId($order), 'paymentMethodCode' => $method->getCode()];

        $data = $this->json('POST', $uri, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This order cannot be paid for the moment.', (string) json_encode($data));
    }

    public function testAPaymentRequestCanBeCreatedForAnOrderWithoutBlock(): void
    {
        $method = $this->paymentMethod('Bank transfer');
        $order = $this->order('completed', $method);

        $data = $this->json('POST', sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue()), [
            'paymentId' => $this->paymentId($order),
            'paymentMethodCode' => $method->getCode(),
        ]);

        self::assertStringNotContainsString('This order cannot be paid for the moment.', (string) json_encode($data));
        self::assertResponseStatusCodeSame(201);
    }

    private function paymentId(OrderInterface $order): string
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW) ?? $order->getLastPayment();
        self::assertNotNull($payment);
        self::assertIsInt($payment->getId());

        return (string) $payment->getId();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function json(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: null === $body ? null : (string) json_encode($body));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
