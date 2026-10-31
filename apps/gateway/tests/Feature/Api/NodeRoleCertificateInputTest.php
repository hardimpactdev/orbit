<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Setting;
use Tests\Support\Fleet\FleetFixtures;

function roleCertificateCapture(): NodeRoleCertificateInput
{
    return new NodeRoleCertificateInput(new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new RuntimeException('Public capture must never sign a certificate.');
        }

        public function rootCertificate(): string
        {
            return 'role-public-root';
        }
    }, new MetricsGatewayResolver);
}

it('captures Metrics certificate on the exact serving Gateway without changing published state', function (): void {
    $gateway = FleetFixtures::node('certificate-gateway', [RoleName::Gateway]);
    $metrics = FleetFixtures::node('certificate-metrics', [RoleName::Metrics]);
    $before = Setting::query()->count();

    $snapshot = roleCertificateCapture()->capture($metrics, RoleName::Metrics);

    expect($snapshot['leaves'])->toHaveCount(1);
    expect($snapshot['leaves'][0])->toMatchArray(['node_id' => $gateway->id,
        'scope' => 'role-metrics', 'domain' => 'metrics.orbit', 'root_hash' => hash('sha256', 'role-public-root')]);
    expect($snapshot['leaves'][0]['version'])->toMatch('/^[a-f0-9]{16}$/');
    expect($snapshot['withdrawals'])->toBe([]);
    expect(NodeRoleCertificateInput::participants($snapshot))->toBe([$gateway->id]);
    expect(Setting::query()->count())->toBe($before);
});

it('refuses ambiguous Metrics serving Gateway before returning certificate selectors', function (): void {
    $metrics = FleetFixtures::node('certificate-metrics-no-gateway', [RoleName::Metrics]);

    expect(fn () => roleCertificateCapture()->capture($metrics, RoleName::Metrics))
        ->toThrow(ResourceOperationException::class, 'exactly one active Gateway');
});

it('captures WebSocket target certificate and immutable source withdrawal for relocation', function (): void {
    $source = FleetFixtures::node('certificate-old-reverb');
    $target = FleetFixtures::node('certificate-new-reverb', [RoleName::WebSocket]);

    $snapshot = roleCertificateCapture()->capture($target, RoleName::WebSocket, $source);

    expect($snapshot['leaves'][0])->toMatchArray(['node_id' => $target->id, 'scope' => 'role-websocket', 'domain' => 'reverb.orbit']);
    expect($snapshot['withdrawals'])->toBe([['node_id' => $source->id, 'scope' => 'role-websocket']]);
    expect(NodeRoleCertificateInput::participants($snapshot))->toBe([$source->id, $target->id]);
});

it('keeps the shared Gateway certificate when Metrics moves', function (): void {
    $gateway = FleetFixtures::node('certificate-shared-gateway', [RoleName::Gateway]);
    $source = FleetFixtures::node('certificate-old-metrics');
    $target = FleetFixtures::node('certificate-new-metrics', [RoleName::Metrics]);

    $snapshot = roleCertificateCapture()->capture($target, RoleName::Metrics, $source);

    expect($snapshot['leaves'][0]['node_id'])->toBe($gateway->id);
    expect($snapshot['withdrawals'])->toBe([]);
});

it('captures Analytics certificate on its target and withdraws only its relocated source', function (): void {
    $source = FleetFixtures::node('certificate-old-analytics');
    $target = FleetFixtures::node('certificate-new-analytics', [RoleName::Analytics]);

    $snapshot = roleCertificateCapture()->capture($target, RoleName::Analytics, $source);

    expect($snapshot['leaves'][0])->toMatchArray(['node_id' => $target->id, 'scope' => 'role-analytics',
        'domain' => 'analytics.orbit', 'root_hash' => hash('sha256', 'role-public-root')]);
    expect($snapshot['withdrawals'])->toBe([['node_id' => $source->id, 'scope' => 'role-analytics']]);
    expect(NodeRoleCertificateInput::participants($snapshot))->toBe([$source->id, $target->id]);
});

it('withdraws only the removed Analytics Node certificate without reading CA material', function (): void {
    $node = FleetFixtures::node('certificate-removing-analytics', [RoleName::Analytics]);
    $capture = new NodeRoleCertificateInput(new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new RuntimeException('Removal never signs a certificate.');
        }

        public function rootCertificate(): string
        {
            throw new RuntimeException('Removal needs no root certificate.');
        }
    }, new MetricsGatewayResolver);

    expect($capture->capture($node, RoleName::Analytics, removing: true))
        ->toBe(['leaves' => [], 'withdrawals' => [['node_id' => $node->id, 'scope' => 'role-analytics']]]);
});

it('withdraws a removed Metrics certificate only when no Metrics role still serves', function (): void {
    $gateway = FleetFixtures::node('certificate-withdraw-gateway', [RoleName::Gateway]);
    $metrics = FleetFixtures::node('certificate-removing-metrics', [RoleName::Metrics]);
    $metrics->roles()->update(['status' => LifecycleStatus::Removing]);

    $snapshot = roleCertificateCapture()->capture($metrics, RoleName::Metrics, removing: true);

    expect($snapshot)->toBe(['leaves' => [], 'withdrawals' => [['node_id' => $gateway->id, 'scope' => 'role-metrics']]]);
});

it('preserves another serving Metrics owner certificate during removal', function (): void {
    FleetFixtures::node('certificate-preserve-gateway', [RoleName::Gateway]);
    FleetFixtures::node('certificate-preserve-metrics', [RoleName::Metrics]);
    $metrics = FleetFixtures::node('certificate-retiring-metrics', [RoleName::Metrics]);
    $metrics->roles()->update(['status' => LifecycleStatus::Removing]);

    expect(roleCertificateCapture()->capture($metrics, RoleName::Metrics, removing: true))
        ->toBe(['leaves' => [], 'withdrawals' => []]);
});

it('moves Metrics publication with Gateway while leaving Gateway web deployment to its owner', function (): void {
    $source = FleetFixtures::node('certificate-old-gateway');
    $target = FleetFixtures::node('certificate-new-gateway', [RoleName::Gateway]);
    FleetFixtures::node('certificate-attached-metrics', [RoleName::Metrics]);

    $snapshot = roleCertificateCapture()->capture($target, RoleName::Gateway, $source);

    expect(array_column($snapshot['leaves'], 'scope'))->toBe(['role-metrics']);
    expect($snapshot['leaves'][0]['node_id'])->toBe($target->id);
    expect($snapshot['withdrawals'])->toBe([['node_id' => $source->id, 'scope' => 'role-metrics']]);
});

it('needs no CA material for roles with no role certificate publication', function (RoleName $role): void {
    $node = FleetFixtures::node('certificate-no-leaf-'.$role->value, [$role]);
    $capture = new NodeRoleCertificateInput(new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new RuntimeException('No signing is allowed.');
        }

        public function rootCertificate(): string
        {
            throw new RuntimeException('No root certificate read is needed.');
        }
    }, new MetricsGatewayResolver);

    expect($capture->capture($node, $role))->toBe(['leaves' => [], 'withdrawals' => []]);
})->with([RoleName::Gateway, RoleName::CiRunner, RoleName::Database]);
