<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorManagedProxyRestorer;
use Tests\TestCase;

uses(TestCase::class);

it('keeps managed actions on the correct side of extra route handling', function (): void {
    $restorer = app(DoctorManagedProxyRestorer::class);

    expect($restorer->supportsBeforeExtra('proxy.agent_tool_route_missing'))
        ->toBeTrue()
        ->and($restorer->supportsBeforeExtra('proxy.analytics.router_route_missing'))
        ->toBeTrue()
        ->and($restorer->supportsBeforeExtra('proxy.analytics.public_route_missing'))
        ->toBeTrue()
        ->and($restorer->supportsBeforeExtra('proxy.caddy_container_missing'))
        ->toBeFalse()
        ->and($restorer->supportsAfterExtra('proxy.caddy_container_missing'))
        ->toBeTrue()
        ->and($restorer->supportsAfterExtra('proxy.global_config_mismatch'))
        ->toBeTrue()
        ->and($restorer->supportsAfterExtra('proxy.websocket.public_route_missing'))
        ->toBeTrue()
        ->and($restorer->supportsAfterExtra('proxy.s3.public_route_missing'))
        ->toBeTrue()
        ->and($restorer->supportsAfterExtra('proxy.route_mismatch'))
        ->toBeFalse();
});
