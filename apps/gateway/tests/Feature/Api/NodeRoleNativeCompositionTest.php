<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Models\Node;
use App\Models\NodeFootprint as RecordedFootprint;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    app()->instance(NodeRoleCertificateInput::class, new NodeRoleCertificateInput(new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new RuntimeException('Native admission and projection must not sign certificates.');
        }

        public function rootCertificate(): string
        {
            return 'native-composition-public-root';
        }
    }, new MetricsGatewayResolver));
    $this->gateway = FleetFixtures::node('composition-gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('composition-role', [RoleName::AppDev]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [$this->gateway->id, $this->node->id]]);
});

function nodeNativeCompositionOperation(Node $node, NodeRole $assignment, array $snapshot): Operation
{
    return Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['node_id' => $node->id, 'role' => $assignment->role->value, '_role' => $snapshot,
            'participant_node_ids' => array_values(array_unique([$node->id, ...($snapshot['publication']['caddy_node_ids'] ?? []),
                ...($snapshot['publication']['php_node_ids'] ?? []), ...($snapshot['publication']['dns_node_ids'] ?? [])]))],
        'state' => ['swamp_run_id' => '98e7e44c-234b-4c59-b983-77ac0eb357bd']]);
}

function nodeNativeCompositionProjection(array $result = [], string $status = 'running'): array
{
    return ['run_id' => '98e7e44c-234b-4c59-b983-77ac0eb357bd', 'status' => $status,
        'error_code' => null, 'started_at' => '2026-10-31T09:00:00Z', 'finished_at' => '2026-10-31T09:00:01Z', 'result' => $result];
}

it('freezes certificate Metrics and Gateway DNS composition in the admitted role snapshot', function (): void {
    $vpn = FleetFixtures::node('composition-vpn', [RoleName::Vpn]);
    $metrics = FleetFixtures::node('composition-metrics', [RoleName::Metrics]);
    $assignment = $this->gateway->roles()->where('role', RoleName::Gateway)->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->gateway->refresh(), $assignment, false);
    $operation = nodeNativeCompositionOperation($this->gateway, $assignment, $snapshot);

    expect($snapshot['certificates']['leaves'][0])->toMatchArray(['node_id' => $this->gateway->id, 'scope' => 'role-metrics',
        'domain' => 'metrics.orbit', 'root_hash' => hash('sha256', 'native-composition-public-root')])
        ->and($snapshot['metrics_fleet']['metrics_node_id'])->toBe($metrics->id)
        ->and(array_column($snapshot['metrics_fleet']['fleet'], 'node_id'))->toBe([$this->gateway->id, $this->node->id, $vpn->id, $metrics->id])
        ->and($snapshot['gateway_dns'])->toBe(['operation' => 'converge', 'address' => $vpn->wireguard_ip, 'domain' => 'orbit']);

    $metrics->update(['name' => 'composition-renamed-after-admission']);
    $vpn->update(['wireguard_ip' => '10.44.0.254']);
    expect($operation->refresh()->input['_role'])->toBe($snapshot)
        ->and(json_encode($snapshot))->not->toContain('secret_hash', 'password', 'private_key');
});

it('admits the complete fleet and keeps Metrics private material readable only by its owner', function (): void {
    $metrics = FleetFixtures::node('composition-admitted-metrics', [RoleName::Metrics]);
    $vpn = FleetFixtures::node('composition-admitted-vpn', [RoleName::Vpn]);
    $other = FleetFixtures::node('composition-admitted-unrelated', [RoleName::Database]);
    DB::beginTransaction();
    try {
        $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
        $expected = [$this->node->id, $this->gateway->id, $metrics->id, $vpn->id, $other->id];
        sort($expected);
        $participants = $operation->input['participant_node_ids'];
        sort($participants);
        expect($participants)->toBe($expected)
            ->and($operation->input['metrics_runtime'])->toBe(['$secret' => 'metrics.fleet_runtime', 'id' => $metrics->id])
            ->and($operation->input['private_bindings']['metrics_runtime'])->toBe([
                'owner_node_id' => $metrics->id, 'read_node_ids' => [$metrics->id], 'write_node_ids' => [],
            ]);
        $bindings = app(DirectOperationPrivateBindings::class);
        $declared = app(DirectOperation::class)->definition('node.role_add')['private_material'];
        expect($bindings->ownerNode($operation, 'metrics_runtime'))->toBe($metrics->id)
            ->and($bindings->readable($operation, $declared, $metrics->id))->toBe(['metrics_runtime' => 'metrics.fleet_runtime'])
            ->and($bindings->readable($operation, $declared, $this->node->id))->not->toHaveKey('metrics_runtime');
        expect(fn () => $bindings->readable($operation, $declared, $other->id))->toThrow(ResourceOperationException::class);
        $public = app(NodeRoleDirectProjection::class)->publicInput($operation, $metrics->id);
        expect($public['role'])->toBe('database')->and($public['metrics_fleet'])->toBe($operation->input['_role']['metrics_fleet'])
            ->and(json_encode($public))->not->toContain('$secret', 'runtime_material', 'metrics_runtime');
    } finally {
        DB::rollBack();
    }
});

it('records a running certificate phase before the next fresh public Caddy render', function (): void {
    $assignment = $this->node->roles()->create(['role' => RoleName::WebSocket, 'status' => 'provisioning']);
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node->refresh(), $assignment, true);
    $operation = nodeNativeCompositionOperation($this->node, $assignment, $snapshot);
    expect(app(WebSocketCaddySiteSource::class)->sites($this->node))->toBe([]);

    app(NodeRoleDirectProjection::class)->apply($operation, nodeNativeCompositionProjection([
        'publication_phase' => ['phase' => 'certificates_published', 'node_id' => $this->node->id],
    ]));

    expect(app(CaddySiteCertificates::class)->published($this->node->id, 'websocket'))->toBeTrue()
        ->and(app(WebSocketCaddySiteSource::class)->sites($this->node))->toHaveCount(1);
    $public = app(NodeRoleDirectProjection::class)->publicInput($operation->refresh(), $this->node->id);
    expect(json_encode($public['caddy_publication']))->toContain('reverb.orbit');
});

it('recaptures footprint once and records terminal artifacts against that refreshed expected digest', function (): void {
    $artifact = new class implements NodeFootprintArtifact
    {
        public string $desired = 'before-publication';

        public int $calls = 0;

        public function name(): string
        {
            return 'tmpfiles';
        }

        public function applies(Node $node): bool
        {
            return true;
        }

        public function digest(Node $node): string
        {
            $this->calls++;

            return $this->desired;
        }
    };
    $footprint = new NodeFootprint(app(AgentDelivery::class), [$artifact]);
    app()->instance(NodeFootprint::class, $footprint);
    app()->instance(NodeConvergeInput::class, new NodeConvergeInput($footprint, app(AgentDelivery::class), [$artifact]));
    $assignment = $this->node->roles()->create(['role' => RoleName::Database, 'status' => 'provisioning']);
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node->refresh(), $assignment, true);
    $operation = nodeNativeCompositionOperation($this->node, $assignment, $snapshot);
    $admittedDigest = $snapshot['footprint']['artifacts'][0]['digest'];
    $artifact->desired = 'after-publication';
    $projection = app(NodeRoleDirectProjection::class);
    $prepare = nodeNativeCompositionProjection(['prepare_footprint' => true]);
    $projection->apply($operation, $prepare);
    $refreshed = $operation->refresh()->state['role_footprint'];
    $afterFirst = $artifact->calls;
    expect($refreshed['artifacts'][0]['digest'])->not->toBe($admittedDigest)
        ->and($operation->input['_role']['footprint'])->toBe($snapshot['footprint'])
        ->and($projection->input($operation)['footprint'])->toBe($refreshed);

    $artifact->desired = 'later-unrelated-change';
    $projection->apply($operation, $prepare);
    expect($artifact->calls)->toBe($afterFirst)->and($operation->refresh()->state['role_footprint'])->toBe($refreshed);
    $terminal = nodeNativeCompositionProjection(['footprint' => ['footprint_proven' => true,
        'artifacts' => [['name' => 'tmpfiles', 'status' => 'applied']]],
        'cli' => ['completed' => true, 'outcome' => 'cli_present', 'configured' => true]], 'succeeded');
    $projection->apply($operation, $terminal);
    expect(RecordedFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts)
        ->toBe(['tmpfiles' => $refreshed['artifacts'][0]['digest']]);
});

it('does not repeat role or footprint writes when the same terminal native result is replayed', function (): void {
    $assignment = $this->node->roles()->create(['role' => RoleName::Database, 'status' => 'provisioning']);
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node->refresh(), $assignment, true);
    $operation = nodeNativeCompositionOperation($this->node, $assignment, $snapshot);
    $terminal = nodeNativeCompositionProjection(['footprint' => ['footprint_proven' => true, 'artifacts' => []],
        'cli' => ['completed' => true, 'outcome' => 'cli_present', 'configured' => true]], 'succeeded');
    $projection = app(NodeRoleDirectProjection::class);
    $projection->apply($operation, $terminal);
    $sequence = $operation->refresh()->sequence;
    $assignment->update(['error_code' => 'native.terminal_replay_sentinel']);
    RecordedFootprint::query()->where('node_id', $this->node->id)->update(['converged_at' => '2020-01-01 00:00:00']);

    $projection->apply($operation, $terminal);

    expect($operation->refresh()->sequence)->toBe($sequence)
        ->and($assignment->refresh()->error_code)->toBe('native.terminal_replay_sentinel')
        ->and(RecordedFootprint::query()->where('node_id', $this->node->id)->sole()->converged_at->toDateString())->toBe('2020-01-01');
});
