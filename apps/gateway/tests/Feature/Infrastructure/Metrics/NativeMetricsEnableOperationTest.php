<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

it('records Metrics enable as role add and replays the same request before singleton checks', function (): void {
    FleetFixtures::bind();
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn('fixture-public-root');
    app()->instance(LeafCertificateSigner::class, $signer);
    $gateway = FleetFixtures::node('gateway', [RoleName::Gateway]);
    $this->markAsGateway($gateway);
    $node = FleetFixtures::node('fresh-metrics');
    $node->forceFill(['user' => 'orbit', 'agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [$node->id]]);
    $idempotency = app(OperationIdempotency::class);
    $idempotency->kind = 'node.role_add';
    $idempotency->key = 'metrics-enable-once';
    $idempotency->fingerprint = str_repeat('f', 64);

    DB::beginTransaction();
    try {
        $first = app(NativeMetricsRoleManager::class)->enable($node->id);
        $second = app(NativeMetricsRoleManager::class)->enable($node->id);

        expect($first->kind)->toBe('node.role_add')->and($second->id)->toBe($first->id);
        $this->assertDatabaseCount('operations', 1);
        expect($node->roles()->where('role', RoleName::Metrics)->count())->toBe(1);
    } finally {
        DB::rollBack();
    }
});

it('refuses a second Metrics assignment without admitting another operation', function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('assigned', [RoleName::Metrics]);
    $target = FleetFixtures::node('other');
    expect(fn () => app(NativeMetricsRoleManager::class)->enable($target->id))->toThrow(RoleAssignmentException::class);
    expect(Operation::query()->count())->toBe(0)->and($target->roles()->count())->toBe(0);
});

it('rejects unsupported Metrics host platforms before role admission', function (string $platform): void {
    FleetFixtures::bind();
    $target = FleetFixtures::node('unsupported', platform: $platform);
    expect(fn () => app(NativeMetricsRoleManager::class)->enable($target->id))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.platform_unsupported'));
    expect(Operation::query()->count())->toBe(0)->and($target->roles()->count())->toBe(0);
})->with(['macos', 'ipados']);
