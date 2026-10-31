<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('role-projection', [RoleName::AppDev, RoleName::Database]);
    $this->assignment = $this->node->roles->firstWhere('role', RoleName::Database);
    $this->assignment->update(['status' => 'provisioning']);
    $this->snapshot = app(NodeRoleDirectInput::class)->capture($this->node->refresh(), $this->assignment, true);
    $this->operation = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['node_id' => $this->node->id, 'role' => 'database', '_role' => $this->snapshot],
        'state' => ['node_role_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
            'role_intent' => NodeRoleIntent::capture($this->assignment, true)]]);
    $this->data = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded', 'error_code' => null,
        'started_at' => '2026-10-31T03:00:00Z', 'finished_at' => '2026-10-31T03:00:01Z',
        'result' => ['role_proven' => true, 'assignment_id' => $this->assignment->id, 'created' => true,
            'baseline' => array_map(static fn (string $stage): array => ['stage' => $stage, 'ok' => true], ['prerequisites', 'ci-host', 'firewall']),
            'footprint' => ['footprint_proven' => true, 'artifacts' => []], 'cli' => ['completed' => true, 'outcome' => 'cli_present', 'reason' => null, 'version' => null, 'configured' => true], 'follow_up' => null]];
});

it('projects one proven role result and leaves replays unchanged', function (): void {
    $projection = app(NodeRoleDirectProjection::class);
    $projection->apply($this->operation, $this->data);
    expect($this->assignment->refresh()->status->value)->toBe('active');
    $sequence = $this->operation->refresh()->sequence;
    $projection->apply($this->operation, $this->data);
    expect($this->operation->refresh()->sequence)->toBe($sequence)->and($this->operation->children()->count())->toBe(0);
});

it('rejects missing duplicated foreign or diagnostic-bearing proof before changing the assignment', function (string $reason): void {
    match ($reason) {
        'missing-baseline' => array_pop($this->data['result']['baseline']),
        'duplicate-baseline' => $this->data['result']['baseline'][1]['stage'] = 'prerequisites',
        'assignment' => $this->data['result']['assignment_id']++,
        'diagnostics' => $this->data['result']['stdout'] = 'unsafe diagnostics',
        'follow-up' => $this->data['result']['follow_up'] = 'fake success',
        'unproven-footprint' => $this->data['result']['footprint'] = ['footprint_proven' => false, 'artifacts' => []],
        'cli-version' => $this->data['result']['cli'] = ['completed' => true, 'outcome' => 'cli_installed', 'reason' => null, 'version' => 'unadmitted-version', 'configured' => true],
        'cli-diagnostics' => $this->data['result']['cli'] = ['error_code' => 'cli.install_failed', 'version' => ['unsafe' => 'diagnostics']],
    };
    expect(fn () => app(NodeRoleDirectProjection::class)->apply($this->operation, $this->data))->toThrow(ResourceOperationException::class);
    expect($this->assignment->refresh()->status->value)->toBe('provisioning')->and($this->operation->refresh()->status->value)->toBe('running');
})->with(['missing-baseline', 'duplicate-baseline', 'assignment', 'diagnostics', 'follow-up', 'unproven-footprint', 'cli-version', 'cli-diagnostics']);

it('keeps known optional footprint and CLI failures as explicit follow-ups', function (): void {
    $this->data['result']['footprint'] = ['footprint_proven' => false, 'artifacts' => [], 'error_code' => 'agent.version_mismatch'];
    $this->data['result']['cli'] = ['error_code' => 'cli.install_failed'];
    $this->data['result']['follow_up'] = 'role-footprint:agent.version_mismatch, role-cli:cli.install_failed';
    app(NodeRoleDirectProjection::class)->apply($this->operation, $this->data);
    expect($this->assignment->refresh()->status->value)->toBe('active')
        ->and($this->operation->refresh()->result['follow_up'])->toBe($this->data['result']['follow_up']);
});

it('marks a refused baseline failed rather than reporting an active role', function (): void {
    $this->data['status'] = 'failed';
    $this->data['error_code'] = 'role.prerequisite_failed';
    $this->data['result'] = ['role_proven' => false, 'assignment_id' => $this->assignment->id, 'created' => true,
        'baseline' => [['stage' => 'prerequisites', 'ok' => false, 'error_code' => 'role.prerequisite_failed']],
        'error_code' => 'role.prerequisite_failed', 'failed_step' => 'prerequisites'];
    app(NodeRoleDirectProjection::class)->apply($this->operation, $this->data);
    expect($this->assignment->refresh()->status->value)->toBe('failed')
        ->and($this->assignment->error_code)->toBe('role.prerequisite_failed');
});
