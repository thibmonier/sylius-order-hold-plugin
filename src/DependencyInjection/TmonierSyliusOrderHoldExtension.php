<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCandidateQueryExtensionInterface;

final class TmonierSyliusOrderHoldExtension extends Extension
{
    public const TAG_UNPAID_ORDER_CANCELLATION_EXCLUSION = 'tmonier_order_hold.unpaid_order_cancellation_exclusion';

    public const TAG_UNPAID_ORDER_CANDIDATE_QUERY_EXTENSION = 'tmonier_order_hold.unpaid_order_candidate_query_extension';

    public const TAG_PAYMENT_METHOD_ELIGIBILITY_CHECKER = 'tmonier_order_hold.payment_method_eligibility_checker';

    public const TAG_PAYMENT_ENTRY_BLOCKER = 'tmonier_order_hold.payment_entry_blocker';

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{unpaid_orders: array{batch_size: int}, check_state_machine_adapters: bool} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('tmonier_order_hold.unpaid_orders.batch_size', $config['unpaid_orders']['batch_size']);
        $container->setParameter('tmonier_order_hold.check_state_machine_adapters', $config['check_state_machine_adapters']);

        $container->registerForAutoconfiguration(UnpaidOrderCancellationExclusionInterface::class)->addTag(self::TAG_UNPAID_ORDER_CANCELLATION_EXCLUSION);
        $container->registerForAutoconfiguration(UnpaidOrderCandidateQueryExtensionInterface::class)->addTag(self::TAG_UNPAID_ORDER_CANDIDATE_QUERY_EXTENSION);
        $container->registerForAutoconfiguration(PaymentMethodEligibilityCheckerInterface::class)->addTag(self::TAG_PAYMENT_METHOD_ELIGIBILITY_CHECKER);
        $container->registerForAutoconfiguration(PaymentEntryBlockerInterface::class)->addTag(self::TAG_PAYMENT_ENTRY_BLOCKER);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }
}
