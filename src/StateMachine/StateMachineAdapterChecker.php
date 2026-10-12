<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\StateMachine;

use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * The order-hold plugins listen to Symfony Workflow events of the checkout, order and payment
 * graphs: refuses winzou for them. Called at container compilation, and at runtime (first
 * request or console command) when the adapters come from environment variables.
 */
final class StateMachineAdapterChecker
{
    public const CHECKED_GRAPHS = [
        OrderCheckoutTransitions::GRAPH,
        OrderTransitions::GRAPH,
        PaymentTransitions::GRAPH,
    ];

    private const WINZOU_ADAPTER = 'winzou_state_machine';

    private bool $checked = false;

    /** @param array<mixed> $graphsToAdaptersMapping */
    public function __construct(
        private readonly mixed $defaultAdapter = null,
        private readonly array $graphsToAdaptersMapping = [],
    ) {
    }

    /** Event listener (kernel.request, console.command): checks once per process. */
    public function __invoke(): void
    {
        if ($this->checked) {
            return;
        }
        $this->checked = true;

        self::check($this->defaultAdapter, $this->graphsToAdaptersMapping);
    }

    /**
     * @param array<mixed> $graphsToAdaptersMapping
     *
     * @throws \LogicException
     */
    public static function check(mixed $defaultAdapter, array $graphsToAdaptersMapping): void
    {
        $winzouGraphs = array_values(array_filter(
            self::CHECKED_GRAPHS,
            static fn (string $graph): bool => self::WINZOU_ADAPTER === ($graphsToAdaptersMapping[$graph] ?? $defaultAdapter),
        ));

        if ([] !== $winzouGraphs) {
            throw new \LogicException(sprintf(
                'tmonier/sylius-order-hold-plugin requires the Symfony Workflow state machine adapter for the "%s" graph(s), which are mapped to winzou (sylius_state_machine_abstraction). Map them to "symfony_workflow", or set tmonier_sylius_order_hold.check_state_machine_adapters to false if no installed plugin relies on workflow events.',
                implode('", "', $winzouGraphs),
            ));
        }
    }
}
