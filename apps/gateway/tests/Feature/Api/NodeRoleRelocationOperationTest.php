<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('relocation-gateway', [RoleName::Gateway]);
    $this->source = FleetFixtures::node('relocation-source', [RoleName::WebSocket]);
    $this->target = FleetFixtures::node('relocation-target');
    $this->target->update(['agent_secret_hash' => str_repeat('e', 64)]);
    config(['swamp_workers.nodes' => [$this->source->id, $this->target->id]]);
    app(NodeRoleRuntimeInput::class)->prepare($this->source, $this->source->roles()->sole());
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    DB::beginTransaction();
});
afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('copies source credentials without replacing an existing destination credential', function (?string $existing): void {
    $settings = app(SettingRepository::class);
    $key = 'websocket.reverb.laravel_app_key';
    $source = new SettingScope(SettingScopeType::Node, $this->source->id);
    $target = new SettingScope(SettingScopeType::Node, $this->target->id);
    $settings->put($source, $key, 'source-private', SettingValueProtection::Secret);
    if ($existing !== null) {
        $settings->put($target, $key, $existing, SettingValueProtection::Secret);
    }
    $assignmentId = $this->source->roles()->sole()->id;

    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);

    expect($this->source->roles()->count())->toBe(0)
        ->and($this->target->roles()->sole()->id)->toBe($assignmentId)
        ->and($settings->get($source, $key))->toBe('source-private')
        ->and($settings->get($target, $key))->toBe($existing ?? 'source-private')
        ->and($operation->input['_relocate']['withdrawal_grace_seconds'])->toBe(31)
        ->and($operation->input['participant_node_ids'])->toContain($this->source->id, $this->target->id);
})->with([null, 'existing-target-private']);

it('retains source credentials on failure and deletes only those credentials after native source completion', function (bool $completed): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);
    $run = '405c68f5-e089-46db-bbeb-03a458b1c6c4';
    $operation->update(['state' => [...$operation->state, 'swamp_run_id' => $run]]);
    app(NodeRoleDirectProjection::class)->apply($operation, [
        'run_id' => $run, 'status' => $completed ? 'running' : 'failed',
        'started_at' => '2026-10-31T10:30:00Z', 'finished_at' => '2026-10-31T10:31:00Z',
        'error_code' => $completed ? null : 'node_role.runtime_failed',
        'result' => $completed ? ['source_completed' => true] : ['error_code' => 'node_role.runtime_failed'],
    ]);
    $settings = app(SettingRepository::class);
    $key = 'websocket.reverb.laravel_app_key';
    expect($settings->get(new SettingScope(SettingScopeType::Node, $this->source->id), $key) === null)->toBe($completed)
        ->and($settings->get(new SettingScope(SettingScopeType::Node, $this->target->id), $key))->toBeString();
})->with([false, true]);
