<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\Fleet\FleetFixtures;

/** @return array{Operation,NodeRole,array{run_id:string,status:string,error_code:?string,started_at:string,finished_at:string,result:array<string,mixed>}} */
function nativeRoleConvergeProjectionFixture(RoleName $role = RoleName::Database): array
{
    FleetFixtures::bind();
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public-role-convergence-root';
        }
    });
    $node = FleetFixtures::node('role-converge', [$role]);
    $assignment = $node->roles->sole();
    $assignment->claimConvergence();
    $intent = app(NodeRoleDirectInput::class)->capture($node, $assignment, false);
    $run = '46382a57-31e5-4b13-8938-c14a7951e05f';
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['intent' => $intent, 'execution_secrets' => []],
        'state' => ['swamp_run_id' => $run, 'role_intent' => NodeRoleIntent::capture($assignment, false)]]);

    return [$operation, $assignment, ['run_id' => $run, 'status' => 'succeeded', 'error_code' => null,
        'started_at' => '2026-10-31T20:00:00Z', 'finished_at' => '2026-10-31T20:00:01Z',
        'result' => ['role_proven' => true, 'target_completed' => true,
            'assignment_id' => $assignment->id, 'created' => false, 'baseline' => [],
            'runtime_completed' => false, 'publication_phases' => [],
            'footprint' => ['footprint_proven' => false, 'artifacts' => [], 'error_code' => 'node.footprint_failed'],
            'cli' => ['completed' => true, 'outcome' => 'skipped'], 'follow_up' => 'role-footprint:node.footprint_failed']]];
}

it('supplies only its captured public intent and execution references without rebuilding them', function (): void {
    [$operation] = nativeRoleConvergeProjectionFixture();
    $projection = app(NodeRoleDirectProjection::class);

    expect($projection->input($operation))->toBe($operation->input);
});

it('projects one native convergence result and leaves terminal replays unchanged', function (): void {
    [$operation, $assignment, $data] = nativeRoleConvergeProjectionFixture();
    $projection = app(NodeRoleDirectProjection::class);

    $projection->apply($operation, $data);
    $sequence = $operation->refresh()->sequence;
    $projection->apply($operation, $data);

    expect($operation->refresh()->sequence)->toBe($sequence)
        ->and($operation->result)->toBe($data['result'])
        ->and($assignment->refresh()->status->value)->toBe('active');
});

it('keeps a completed target active when a later independent step fails', function (): void {
    [$operation, $assignment, $data] = nativeRoleConvergeProjectionFixture();
    $data['status'] = 'failed';
    $data['error_code'] = 'operation.native_failed';
    $data['result']['error_code'] = 'node.footprint_failed';
    $data['result']['failed_step'] = 'footprint';

    app(NodeRoleDirectProjection::class)->apply($operation, $data);

    expect($assignment->refresh()->status->value)->toBe('active')
        ->and($operation->refresh()->status->value)->toBe('failed')
        ->and(($operation->state['role_target_completed'] ?? null))->toBeTrue();
});

it('marks an uncompleted target failed on native refusal', function (): void {
    [$operation, $assignment, $data] = nativeRoleConvergeProjectionFixture();
    $data['status'] = 'failed';
    $data['error_code'] = 'role.prerequisite_failed';
    $data['result'] = ['role_proven' => false, 'target_completed' => false,
        'failed_step' => 'packages', 'error_code' => 'role.prerequisite_failed'];

    app(NodeRoleDirectProjection::class)->apply($operation, $data);

    expect($assignment->refresh()->status->value)->toBe('failed')
        ->and($assignment->refresh()->error_code)->toBe('role.prerequisite_failed');
});

it('records completed certificate publication at terminal without inventing a serving phase', function (): void {
    [$operation, $assignment, $data] = nativeRoleConvergeProjectionFixture(RoleName::WebSocket);
    $data['status'] = 'failed';
    $data['error_code'] = 'caddy.publication_failed';
    $data['result'] = ['role_proven' => false, 'target_completed' => false,
        'failed_step' => 'caddy', 'error_code' => 'caddy.publication_failed',
        'publication_phases' => [['phase' => 'certificates_published', 'node_id' => $operation->node_id]]];

    app(NodeRoleDirectProjection::class)->apply($operation, $data);

    expect(app(CaddySiteCertificates::class)->published($assignment->node_id, 'websocket'))->toBeTrue()
        ->and(app(WebSocketDnsTarget::class)->isServing($assignment->node_id))->toBeFalse()
        ->and($operation->refresh()->status->value)->toBe('failed');
});

it('projects the Analytics runtime contribution from the terminal receipt only', function (bool $completed): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('converge-analytics', [RoleName::Analytics]);
    $assignment = $node->roles->sole();
    $assignment->claimConvergence();
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id,
        'name' => 'plausible', 'runtime' => 'docker', 'runtime_config' => ['image' => 'ghcr.io/plausible/community-edition:v3.2.1'],
        'restart_policy' => 'unless-stopped', 'desired_state' => 'stopped', 'status' => 'provisioning']);
    $statuses = Mockery::mock(ProcessRuntimeStatusIndex::class);
    $statuses->shouldNotReceive('statuses');
    if ($completed) {
        $statuses->shouldReceive('remember')->once()->with(Mockery::on(static fn (Process $item): bool => $item->id === $process->id), 'running');
    } else {
        $statuses->shouldNotReceive('remember');
    }
    app()->instance(ProcessRuntimeStatusIndex::class, $statuses);
    $snapshot = ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => 'analytics',
        'runtime' => ['role' => 'analytics', 'node_id' => $node->id,
            'installation' => ['process_id' => $process->id, 'runtime' => 'docker']],
        'footprint' => ['node_id' => $node->id, 'artifacts' => []],
        'certificates' => ['leaves' => [], 'withdrawals' => []]];
    $run = 'c7f84de2-f5bd-46fb-8bfb-ce2cfc31eeba';
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['intent' => $snapshot, 'execution_secrets' => []], 'state' => ['swamp_run_id' => $run]]);

    app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed',
        'error_code' => 'certificate.publish_failed', 'started_at' => '2026-10-31T20:00:00Z',
        'finished_at' => '2026-10-31T20:00:01Z', 'result' => ['runtime_completed' => $completed,
            'failed_step' => 'certificates', 'error_code' => 'certificate.publish_failed']]);

    expect($process->refresh()->status)->toBe($completed ? LifecycleStatus::Active : LifecycleStatus::Provisioning)
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Failed);
})->with([true, false]);
