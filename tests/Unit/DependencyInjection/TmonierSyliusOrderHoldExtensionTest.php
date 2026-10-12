<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tmonier\SyliusOrderHoldPlugin\DependencyInjection\TmonierSyliusOrderHoldExtension;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCandidateQueryExtensionInterface;

final class TmonierSyliusOrderHoldExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame(100, $container->getParameter('tmonier_order_hold.unpaid_orders.batch_size'));
        self::assertTrue($container->getParameter('tmonier_order_hold.check_state_machine_adapters'));
    }

    public function testConfiguration(): void
    {
        $container = $this->load([['unpaid_orders' => ['batch_size' => 20], 'check_state_machine_adapters' => false]]);

        self::assertSame(20, $container->getParameter('tmonier_order_hold.unpaid_orders.batch_size'));
        self::assertFalse($container->getParameter('tmonier_order_hold.check_state_machine_adapters'));
    }

    public function testEachSyliusServiceIsDecoratedOnce(): void
    {
        $container = $this->load([]);

        $decorated = [];
        foreach ($container->getDefinitions() as $definition) {
            $decoratedService = $definition->getDecoratedService();
            if (null !== $decoratedService) {
                $decorated[] = $decoratedService[0];
            }
        }
        sort($decorated);

        self::assertSame([
            'sylius.provider.payment.order',
            'sylius.resolver.payment_method.default',
            'sylius.resolver.payment_methods',
            'sylius.updater.unpaid_orders_state',
            'sylius_api.changer.payment_method',
        ], $decorated);
    }

    public function testExtensionPointsAreAutoconfigured(): void
    {
        $autoconfigured = $this->load([])->getAutoconfiguredInstanceof();

        $expected = [
            UnpaidOrderCancellationExclusionInterface::class => TmonierSyliusOrderHoldExtension::TAG_UNPAID_ORDER_CANCELLATION_EXCLUSION,
            UnpaidOrderCandidateQueryExtensionInterface::class => TmonierSyliusOrderHoldExtension::TAG_UNPAID_ORDER_CANDIDATE_QUERY_EXTENSION,
            PaymentMethodEligibilityCheckerInterface::class => TmonierSyliusOrderHoldExtension::TAG_PAYMENT_METHOD_ELIGIBILITY_CHECKER,
            PaymentEntryBlockerInterface::class => TmonierSyliusOrderHoldExtension::TAG_PAYMENT_ENTRY_BLOCKER,
        ];
        foreach ($expected as $interface => $tag) {
            self::assertArrayHasKey($interface, $autoconfigured);
            self::assertInstanceOf(Definition::class, $autoconfigured[$interface]);
            self::assertArrayHasKey($tag, $autoconfigured[$interface]->getTags());
        }
    }

    /** @param array<array<string, mixed>> $configs */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new TmonierSyliusOrderHoldExtension())->load($configs, $container);

        return $container;
    }
}
