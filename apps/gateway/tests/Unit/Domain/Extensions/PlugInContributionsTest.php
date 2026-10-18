<?php

declare(strict_types=1);

use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Projects\DestroyProjectAction;
use App\Domain\Extensions\ConfigValue;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\ProxyCli\ProxyCliProcessOwnership;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\ValidateRequestBodySize;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteSource;
use App\Infrastructure\ProxyCli\ProxyCliFootprintArtifact;
use App\Infrastructure\ProxyCli\ProxyCliPrivateHostnames;
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
    'NodeCaddyfileRenderer ProxyCliCaddySiteSource' => [NodeCaddyfileRenderer::class, 'contributions', ProxyCliCaddySiteSource::class],
    'NodeFootprint ProxyCliFootprintArtifact' => [NodeFootprint::class, 'artifacts', ProxyCliFootprintArtifact::class],
    'ReservedPrivateHostname ProxyCliPrivateHostnames' => [ReservedPrivateHostname::class, 'contributions', ProxyCliPrivateHostnames::class],
    'DevelopmentDnsConfigRenderer ProxyCliPrivateHostnames' => [DevelopmentDnsConfigRenderer::class, 'hostnames', ProxyCliPrivateHostnames::class],
    'PrivateDnsFootprintArtifact ProxyCliPrivateHostnames' => [PrivateDnsFootprintArtifact::class, 'contributions', ProxyCliPrivateHostnames::class],
    'CaddyFootprintArtifact ProxyCliCaddySiteSource' => [CaddyFootprintArtifact::class, 'contributions', ProxyCliCaddySiteSource::class],
    'DestroyProcessAction ProxyCliProcessOwnership' => [DestroyProcessAction::class, 'guards', ProxyCliProcessOwnership::class],
]);

it('gives the collector a safe footprint identity that no other wired artifact uses', function (): void {
    $footprint = app(NodeFootprint::class);
    $artifacts = new ReflectionProperty($footprint, 'artifacts')->getValue($footprint);
    $names = array_map(static fn (object $artifact): string => $artifact->name(), [...$artifacts]);
    $name = app(ProxyCliFootprintArtifact::class)->name();

    expect($name)->toBe('proxycli')
        ->and(array_count_values($names)[$name])->toBe(1)
        ->and(ConfigValue::slug($name))->toBeTrue();
});
