<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Tmonier\SyliusOrderHoldPlugin\DependencyInjection\Compiler\StateMachineAdapterCheckPass;

final class StateMachineAdapterCheckPassTest extends TestCase
{
    public function testSymfonyWorkflowIsAccepted(): void
    {
        $container = $this->container('symfony_workflow', ['sylius_catalog_promotion' => 'winzou_state_machine']);

        (new StateMachineAdapterCheckPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testAWinzouMappedGraphIsRefused(): void
    {
        $container = $this->container('symfony_workflow', ['sylius_payment' => 'winzou_state_machine']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"sylius_payment"');
        (new StateMachineAdapterCheckPass())->process($container);
    }

    public function testAWinzouDefaultAdapterIsRefusedUnlessTheGraphIsMappedToSymfonyWorkflow(): void
    {
        $container = $this->container('winzou_state_machine', ['sylius_order_checkout' => 'symfony_workflow']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"sylius_order", "sylius_payment"');
        (new StateMachineAdapterCheckPass())->process($container);
    }

    public function testTheCheckCanBeDisabled(): void
    {
        $container = $this->container('winzou_state_machine', []);
        $container->setParameter('tmonier_order_hold.check_state_machine_adapters', false);

        (new StateMachineAdapterCheckPass())->process($container);

        $this->addToAssertionCount(1);
    }

    /** @param array<string, string> $mapping */
    private function container(string $defaultAdapter, array $mapping): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('tmonier_order_hold.check_state_machine_adapters', true);
        $container->setParameter('sylius_abstraction.state_machine.default_adapter', $defaultAdapter);
        $container->setParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping', $mapping);

        return $container;
    }
}
