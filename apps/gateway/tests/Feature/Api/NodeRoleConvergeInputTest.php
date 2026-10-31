<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Models\Operation;
use App\Models\Setting;
use App\Support\ValidatedData;
use Tests\Support\Fleet\FleetFixtures;

it('captures complete standalone role intent through the canonical 209 descriptor without host effects', function (RoleName $role): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('native-role-input', [$role]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $before = $node->refresh()->getAttributes();
    $settings = Setting::query()->get()->toArray();

    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole());
    $intent = $capture['intent'];
    $ssh = ValidatedData::object($intent['ssh']);
    $target = ValidatedData::object($ssh['target']);

    expect($intent['node_id'])->toBe($node->id)
        ->and($intent['role'])->toBe($role->value)
        ->and($ssh['control_node_id'])->toBe(209)
        ->and($target['host'])->toBe($node->wireguard_ip)
        ->and($ssh['identity_file'])->toBe('/run/credentials/orbit-node-worker.service/proxy-ssh-identity')
        ->and($capture['participant_node_ids'])->toContain($node->id, 209)
        ->and($intent['footprint'])->not->toHaveKey('secret_hash')
        ->and($intent)->not->toHaveKeys(['proxy_node_ids', 'publication'])
        ->and($intent['publications'])->toBeArray()
        ->and($intent['created'])->toBeFalse()
        ->and($node->refresh()->getAttributes())->toBe($before)
        ->and(Setting::query()->get()->toArray())->toBe($settings)
        ->and(Operation::query()->count())->toBe(0);
    $target = ((array) $intent['targets'])[(string) $node->id];
    expect($target)->toBe($intent['ssh']);
    if ($role === RoleName::Database) {
        expect($intent['publications'])->toBe([])->and($intent['caddy_package'])->toBeNull();
    }
})->with([RoleName::Database, RoleName::CiRunner, RoleName::AppDev, RoleName::AppProd]);

it('refuses a foreign role assignment before capturing any standalone intent', function (): void {
    FleetFixtures::bind();
    $owner = FleetFixtures::node('role-owner', [RoleName::Database]);
    $other = FleetFixtures::node('role-other');

    expect(fn () => app(NodeRoleConvergeInput::class)->capture($other, $owner->roles->sole()))
        ->toThrow(ResourceOperationException::class);
});

it('requires the existing target host pin instead of selecting another connection', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-unpinned', [RoleName::Database]);
    $node->update(['ssh_host_key' => null]);

    expect(fn () => app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole()))
        ->toThrow(ResourceOperationException::class);
});

it('captures the final prospective role footprint without storing certificate readiness', function (): void {
    FleetFixtures::bind([app(CaddyFootprintArtifact::class)]);
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public-role-convergence-root';
        }
    });
    FleetFixtures::node('role-gateway', [RoleName::Gateway, RoleName::Vpn]);
    $node = FleetFixtures::node('role-websocket', [RoleName::WebSocket]);
    $settings = Setting::query()->get()->toArray();

    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole());
    $intent = $capture['intent'];
    $publications = $intent['publications'];
    if (! is_array($publications)) {
        throw new RuntimeException('Missing publication list');
    }
    $found = false;
    foreach ($publications as $raw) {
        $row = ValidatedData::object($raw);
        if ($row['node_id'] !== $node->id) {
            continue;
        }
        $found = true;
        $before = ValidatedData::object(ValidatedData::object($row['before'])['caddy']);
        $after = ValidatedData::object(ValidatedData::object($row['after'])['caddy']);
        expect($before['content'])->not->toContain('# orbit: websocket reverb.orbit')
            ->and($after['content'])->toContain('# orbit: websocket reverb.orbit');
    }
    $footprint = ValidatedData::object($intent['footprint']);
    $payloads = ValidatedData::object($footprint['payloads']);
    $caddy = ValidatedData::object($payloads['caddy']);
    expect($found)->toBeTrue()
        ->and($caddy['content'])->toContain('# orbit: websocket reverb.orbit')
        ->and(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeFalse()
        ->and(Setting::query()->get()->toArray())->toBe($settings);
});
