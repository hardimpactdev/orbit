<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdoptGatewayReleaseAction;
use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;
use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\PrepareGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\LocalGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

/** A private property of a resolved object, so the test sees what the container wired. */
function wired(object $object, string $property): mixed
{
    return new ReflectionProperty($object, $property)->getValue($object);
}

beforeEach(function (): void {
    config([
        'orbit.home' => '/home/orbit/.orbit',
        'orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway',
        'orbit.gateway_releases.scheduler_drain_seconds' => 450,
        'orbit.gateway_releases.min_free_mb' => 2048,
        'orbit.gateway_releases.keep' => 4,
        'orbit.gateway_releases.snapshots_keep' => 3,
        'orbit.gateway_releases.tick_confirmation_seconds' => 240,
    ]);
});

describe('the Gateway release pipeline from the container', function (): void {
    it('resolves every release command entry point', function (string $action): void {
        expect(app($action))->toBeInstanceOf($action);
    })->with([
        PrepareGatewayReleaseAction::class,
        DeployGatewayReleaseAction::class,
        RollbackGatewayReleaseAction::class,
        AdoptGatewayReleaseAction::class,
        ConfigureGatewayReleaseAction::class,
        GatewayRuntimeHandoff::class,
    ]);

    it('wires rollback and adopt with the in-process handoff and the deploy path', function (): void {
        $adopter = wired(app(AdoptGatewayReleaseAction::class), 'adopter');

        expect(app(RollbackGatewayReleaseAction::class))->toBeInstanceOf(RollbackGatewayReleaseAction::class)
            ->and($adopter)->toBeInstanceOf(GatewayReleaseAdopter::class)
            ->and(wired($adopter, 'runtime'))->toBeInstanceOf(LocalGatewayReleaseRuntime::class)
            ->and(wired($adopter, 'deploy'))->toBeInstanceOf(DeployGatewayReleaseAction::class)
            ->and(wired($adopter, 'verifier'))->toBeInstanceOf(HttpGatewayReleaseVerifier::class);
    });

    it('wires the handoff with the configured scheduler drain', function (): void {
        $scheduler = wired(app(GatewayRuntimeHandoff::class), 'scheduler');

        expect($scheduler)->toBeInstanceOf(GatewaySchedulerHandoff::class)
            ->and(wired($scheduler, 'drainSeconds'))->toBe(450)
            ->and(wired(app(GatewayRuntimeHandoff::class), 'agent'))->toBeInstanceOf(GatewayNodeAgentUpdate::class)
            ->and(app(GatewayReleaseRuntime::class))->toBeInstanceOf(ArtisanGatewayReleaseRuntime::class)
            ->and(app(GatewayReleaseDatabase::class))->toBeInstanceOf(SqliteGatewayReleaseDatabase::class)
            ->and(app(GatewayReleaseVerifier::class))->toBeInstanceOf(HttpGatewayReleaseVerifier::class);
    });
});

it('prepares release checkout access through the serving Node agent', function (): void {
    $node = FleetFixtures::node('gateway');
    app(GatewayServingHost::class)->remember($node);
    $agents = new FakeAgentCommandRunner()->answer('gateway.prepare-checkout', ['changed' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $prepare = wired(app(GatewayReleaseBuilder::class), 'checkoutAccess');

    $prepare('/home/orbit/releases/0123456789ab/apps/gateway');

    expect($agents->calls)->toBe([['node' => 'gateway', 'command' => 'gateway.prepare-checkout', 'input' => ['path' => '/home/orbit/releases/0123456789ab/apps/gateway']]]);
});
