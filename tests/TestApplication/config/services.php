<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tests\Tmonier\SyliusOrderHoldPlugin\OrderHold\TestOrderHolds;

// Test double implementing every extension point, driven by the tests (inert in dev).
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('tmonier_order_hold.test.order_holds', TestOrderHolds::class)
        ->public()
        ->autoconfigure()
    ;
};
