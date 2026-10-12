<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Tmonier\SyliusOrderHoldPlugin\StateMachine\StateMachineAdapterChecker;

/**
 * Fails at compile time when the checkout, order or payment graph runs on winzou. Adapters
 * coming from environment variables are checked with their build-time values when available,
 * and again at runtime (first request or console command), where the actual values are known.
 */
final class StateMachineAdapterCheckPass implements CompilerPassInterface
{
    public const RUNTIME_CHECKER_ID = 'tmonier_order_hold.state_machine_adapter_checker';

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

        $usesEnv = $container->resolveEnvPlaceholders([$defaultAdapter, $mapping], '') !== [$defaultAdapter, $mapping];
        if ($usesEnv) {
            $container
                ->register(self::RUNTIME_CHECKER_ID, StateMachineAdapterChecker::class)
                ->setArguments([$defaultAdapter, $mapping])
                ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 1024])
                ->addTag('kernel.event_listener', ['event' => 'console.command', 'priority' => 1024])
            ;

            try {
                /** @var array{0: mixed, 1: array<mixed>} $resolved */
                $resolved = $container->resolveEnvPlaceholders([$defaultAdapter, $mapping], true);
                [$defaultAdapter, $mapping] = $resolved;
            } catch (\Throwable) {
                // Not resolvable at build time: the runtime check decides.
                return;
            }
        }

        try {
            StateMachineAdapterChecker::check($defaultAdapter, $mapping);
        } catch (\LogicException $exception) {
            throw new LogicException($exception->getMessage(), 0, $exception);
        }
    }
}
