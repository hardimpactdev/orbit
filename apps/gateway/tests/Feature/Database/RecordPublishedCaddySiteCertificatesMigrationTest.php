<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Models\Node;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Retired slug read by the historical certificate migration. */
it('records a certificate for every role site that renders today and for nothing else', function (): void {
    $gateway = caddy_certificate_migration_node('gateway', [RoleName::Gateway->value => [LifecycleStatus::Active, null]]);
    $websocket = caddy_certificate_migration_node('websocket', [RoleName::WebSocket->value => [LifecycleStatus::Active, null]]);
    $retrying = caddy_certificate_migration_node('analytics', [RoleName::Analytics->value => [LifecycleStatus::Failed, 'converge:analytics-health']]);
    $removing = caddy_certificate_migration_node('leaving', [RoleName::WebSocket->value => [LifecycleStatus::Removing, null]]);
    $removalFailed = caddy_certificate_migration_node('stuck', [RoleName::Analytics->value => [LifecycleStatus::Failed, 'remove:analytics']]);
    $metrics = caddy_certificate_migration_node('metrics', [RoleName::Metrics->value => [LifecycleStatus::Active, null]]);
    $collector = caddy_certificate_migration_node('collector', []);
    foreach (['enabled' => '1', 'node_id' => (string) $collector->id] as $key => $value) {
        DB::table('settings')->insert(['scope_type' => 'gateway', 'scope_id' => 0, 'key' => 'proxycli.'.$key, 'value' => $value, 'is_secret' => false]);
    }
    $migration = caddy_certificate_migration();
    run_legacy_schema_migration($migration, 'down');

    run_legacy_schema_migration($migration, 'up');

    $records = new CaddySiteCertificates;

    expect($records->published($websocket->id, WebSocketCaddySiteSource::CertificateSlug))->toBeTrue()
        ->and($records->published($retrying->id, AnalyticsCaddySiteSource::CertificateSlug))->toBeTrue()
        ->and($records->published($gateway->id, MetricsCaddySiteSource::CertificateSlug))->toBeTrue()
        ->and($records->published($collector->id, 'proxycli'))->toBeTrue()
        ->and($records->published($removing->id, WebSocketCaddySiteSource::CertificateSlug))->toBeFalse()
        ->and($records->published($removalFailed->id, AnalyticsCaddySiteSource::CertificateSlug))->toBeFalse()
        ->and($records->published($metrics->id, MetricsCaddySiteSource::CertificateSlug))->toBeFalse();

    run_legacy_schema_migration($migration, 'down');

    expect($records->published($websocket->id, WebSocketCaddySiteSource::CertificateSlug))->toBeFalse()
        ->and(DB::table('settings')->where('key', 'proxycli.enabled')->value('value'))->toBe('1');
});

it('records no Metrics certificate on the Gateway while no Metrics role renders', function (): void {
    $gateway = caddy_certificate_migration_node('gateway', [RoleName::Gateway->value => [LifecycleStatus::Active, null]]);
    $migration = caddy_certificate_migration();
    run_legacy_schema_migration($migration, 'down');

    run_legacy_schema_migration($migration, 'up');

    expect(new CaddySiteCertificates()->published($gateway->id, MetricsCaddySiteSource::CertificateSlug))->toBeFalse();
});

/** @param array<string, array{LifecycleStatus, ?string}> $roles */
function caddy_certificate_migration_node(string $name, array $roles): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 10),
    ]);

    foreach ($roles as $role => [$status, $failedStep]) {
        $node->roles()->create(['role' => $role, 'status' => $status, 'failed_step' => $failedStep]);
    }

    return $node;
}

function caddy_certificate_migration(): Migration
{
    return require base_path('database/migrations/2026_09_25_130000_record_published_caddy_site_certificates.php');
}
