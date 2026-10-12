<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusOrderHoldPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Tmonier\SyliusOrderHoldPlugin\DependencyInjection\Compiler\StateMachineAdapterCheckPass;
use Tmonier\SyliusOrderHoldPlugin\StateMachine\StateMachineAdapterChecker;

final class StateMachineAdapterCheckPassTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['TMONIER_TEST_SM_ADAPTER'], $_ENV['TMONIER_TEST_SM_ADAPTER']);
    }

    public function testSymfonyWorkflowIsAccepted(): void
    {
        $container = $this->container('symfony_workflow', ['sylius_catalog_promotion' => 'winzou_state_machine']);

        (new StateMachineAdapterCheckPass())->process($container);

        self::assertFalse($container->hasDefinition(StateMachineAdapterCheckPass::RUNTIME_CHECKER_ID));
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

    public function testAnAdapterFromAnEnvVarIsCheckedWithItsBuildTimeValue(): void
    {
        $_SERVER['TMONIER_TEST_SM_ADAPTER'] = 'winzou_state_machine';
        $container = $this->container('%env(TMONIER_TEST_SM_ADAPTER)%', []);

        $this->expectException(LogicException::class);
        (new StateMachineAdapterCheckPass())->process($container);
    }

    public function testAnAdapterFromAnEnvVarIsCheckedAgainAtRuntime(): void
    {
        $container = $this->container('%env(TMONIER_TEST_SM_ADAPTER)%', []);

        (new StateMachineAdapterCheckPass())->process($container);

        self::assertTrue($container->hasDefinition(StateMachineAdapterCheckPass::RUNTIME_CHECKER_ID));
        $tags = $container->getDefinition(StateMachineAdapterCheckPass::RUNTIME_CHECKER_ID)->getTag('kernel.event_listener');
        self::assertSame(['kernel.request', 'console.command'], array_column($tags, 'event'));
    }

    public function testTheRuntimeCheckerRefusesWinzouOnce(): void
    {
        (new StateMachineAdapterChecker('symfony_workflow', []))();

        $checker = new StateMachineAdapterChecker('symfony_workflow', ['sylius_order' => 'winzou_state_machine']);

        try {
            $checker();
            self::fail('Winzou was accepted.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('"sylius_order"', $exception->getMessage());
        }
        // Checked once per process.
        $checker();
    }

    /** @param array<string, string> $mapping */
    private function container(string $defaultAdapter, array $mapping): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('tmonier_order_hold.check_state_machine_adapters', true);
        $container->setParameter('sylius_abstraction.state_machine.default_adapter', $defaultAdapter);
        $container->setParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping', $mapping);
        // Env placeholders are created when the parameter bag is resolved, as in a real build.
        $container->getParameterBag()->resolve();

        return $container;
    }
}
