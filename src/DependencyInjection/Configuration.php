<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_order_hold');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('unpaid_orders')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('batch_size')
                            ->info('Orders read per query by sylius:cancel-unpaid-orders (the entity manager is cleared after each batch).')
                            ->min(1)
                            ->defaultValue(100)
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('check_state_machine_adapters')
                    ->info('Fail at container compilation when the checkout, order or payment graph runs on winzou.')
                    ->defaultTrue()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
