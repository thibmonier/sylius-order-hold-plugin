<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\DependencyInjection\Compiler;

use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * The order-hold plugins listen to Symfony Workflow events of the checkout, order and payment
 * graphs: fail at compile time, with an explicit message, when one of them runs on winzou.
 */
final class StateMachineAdapterCheckPass implements CompilerPassInterface
{
    public const CHECKED_GRAPHS = [
        OrderCheckoutTransitions::GRAPH,
        OrderTransitions::GRAPH,
        PaymentTransitions::GRAPH,
    ];

    private const WINZOU_ADAPTER = 'winzou_state_machine';

    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasParameter('tmonier_order_hold.check_state_machine_adapters') ||
            true !== $container->getParameter('tmonier_order_hold.check_state_machine_adapters') ||
            !$container->hasParameter('sylius_abstraction.state_machine.default_adapter')
        ) {
            return;
        }

        $defaultAdapter = $container->getParameter('sylius_abstraction.state_machine.default_adapter');
        $mapping = $container->hasParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping')
            ? $container->getParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping')
            : [];
        $mapping = is_array($mapping) ? $mapping : [];

        $winzouGraphs = array_values(array_filter(
            self::CHECKED_GRAPHS,
            static fn (string $graph): bool => self::WINZOU_ADAPTER === ($mapping[$graph] ?? $defaultAdapter),
        ));

        if ([] !== $winzouGraphs) {
            throw new LogicException(sprintf(
                'tmonier/sylius-order-hold-plugin requires the Symfony Workflow state machine adapter for the "%s" graph(s), which are mapped to winzou (sylius_state_machine_abstraction.graphs_to_adapters_mapping). Map them to "symfony_workflow", or set tmonier_sylius_order_hold.check_state_machine_adapters to false if no installed plugin relies on workflow events.',
                implode('", "', $winzouGraphs),
            ));
        }
    }
}
