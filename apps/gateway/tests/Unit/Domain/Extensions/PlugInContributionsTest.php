<?php

declare(strict_types=1);

use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Projects\DestroyProjectAction;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\ValidateRequestBodySize;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcessOwnership;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterPrivateHostnames;
use Orbit\Extensions\Conn\Infrastructure\ConnActivityInputRedaction;
use Orbit\Extensions\Documents\Infrastructure\DocumentsActivityInputRedaction;
use Orbit\Extensions\Documents\Infrastructure\DocumentsExactRequestBody;
use Orbit\Extensions\Documents\Infrastructure\DocumentsProjectRemoval;
use Orbit\Extensions\Documents\Infrastructure\DocumentsReleaseCleanup;
use Orbit\Extensions\Documents\Infrastructure\DocumentsServiceStartHook;
use Tests\TestCase;

uses(TestCase::class);

it('injects each contribution into its production consumer once', function (string $consumer, string $property, string $contribution): void {
    $instance = app($consumer);
    $values = new ReflectionProperty($instance, $property)->getValue($instance);
    $classes = array_map(static fn (object $value): string => $value::class, [...$values]);

    expect(array_keys($classes, $contribution, true))->toHaveCount(1);
})->with([
    'DestroyProjectAction DocumentsProjectRemoval' => [DestroyProjectAction::class, 'hooks', DocumentsProjectRemoval::class],
    'GatewayCleanupHandoff DocumentsReleaseCleanup' => [GatewayCleanupHandoff::class, 'cleanups', DocumentsReleaseCleanup::class],
    'RecordCommandActivity DocumentsActivityInputRedaction' => [RecordCommandActivity::class, 'redactions', DocumentsActivityInputRedaction::class],
    'RecordCommandActivity ConnActivityInputRedaction' => [RecordCommandActivity::class, 'redactions', ConnActivityInputRedaction::class],
    'NativeGatewayFpmConverger DocumentsServiceStartHook' => [NativeGatewayFpmConverger::class, 'startHooks', DocumentsServiceStartHook::class],
    'ValidateRequestBodySize DocumentsExactRequestBody' => [ValidateRequestBodySize::class, 'bodies', DocumentsExactRequestBody::class],
    'NodeCaddyfileRenderer AiRouterCaddySiteSource' => [NodeCaddyfileRenderer::class, 'contributions', AiRouterCaddySiteSource::class],
    'NodeFootprint AiRouterFootprintArtifact' => [NodeFootprint::class, 'artifacts', AiRouterFootprintArtifact::class],
    'ReservedPrivateHostname AiRouterPrivateHostnames' => [ReservedPrivateHostname::class, 'contributions', AiRouterPrivateHostnames::class],
    'DevelopmentDnsConfigRenderer AiRouterPrivateHostnames' => [DevelopmentDnsConfigRenderer::class, 'hostnames', AiRouterPrivateHostnames::class],
    'PrivateDnsFootprintArtifact AiRouterPrivateHostnames' => [PrivateDnsFootprintArtifact::class, 'contributions', AiRouterPrivateHostnames::class],
    'CaddyFootprintArtifact AiRouterCaddySiteSource' => [CaddyFootprintArtifact::class, 'contributions', AiRouterCaddySiteSource::class],
    'DestroyProcessAction AiRouterProcessOwnership' => [DestroyProcessAction::class, 'guards', AiRouterProcessOwnership::class],
]);

it('gives the collector a safe footprint identity', function (): void {
    $name = app(AiRouterFootprintArtifact::class)->name();

    expect($name)->toBe('ai-router')
        ->and($name)->toMatch('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D');
});

it('wires core footprint artifacts before extension artifacts in apply order', function (): void {
    $footprint = app(NodeFootprint::class);
    $artifacts = new ReflectionProperty($footprint, 'artifacts')->getValue($footprint);
    $names = array_map(static fn (object $artifact): string => $artifact->name(), [...$artifacts]);

    expect($names)->toBe(['agent', 'caddy-package', 'caddy', 'peer-dns', 'private-dns', 'route-residue', 'tmpfiles', 'ai-router']);
});
