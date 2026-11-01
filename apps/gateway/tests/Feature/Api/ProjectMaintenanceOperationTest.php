<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Projects\ProjectMaintenanceProjection;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $node = $this->markAsGateway(Node::query()->create(['name' => 'project-maintenance-gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2']));
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp-209', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.15', 'public_ssh_host' => '192.0.2.209']);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'Launch', 'slug' => 'launch', 'repository_url' => 'https://github.com/nckrtl/launch.git', 'source_access' => 'github_app', 'task_check' => 'composer check', 'default_branch' => 'main']);
    config(['swamp.run_token' => 'native-fixture']);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
});

it('captures Project settings and the complete group snapshot without executing a group', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Existing', 'brief' => 'Preserve existing work.', 'status' => 'running', 'orchestration' => 'external']);
    $url = '/api/v1/projects/'.$this->project->id.'/maintenance/routine';
    $first = $this->withHeader('Idempotency-Key', 'project-routine')->postJson($url, [])->assertAccepted();
    $operation = Operation::findOrFail($first->json('data.id'));
    expect($operation->kind)->toBe('project.routine')->and($operation->node_id)->toBe(209)
        ->and($operation->input['project'])->toBe(['id' => $this->project->id, 'repository' => 'https://github.com/nckrtl/launch.git', 'default_branch' => 'main', 'check' => 'composer check'])
        ->and($operation->input['groups'][0]['id'])->toBe($group->id)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(app(DirectOperation::class)->definition('project.routine')['terminal_result'])->toBe(['job' => 'routine', 'step' => 'result']);
    $this->project->update(['task_check' => 'new check']);
    $this->postJson($url, [])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->socket->sent)->toHaveCount(1);
});

it('requires a key and rejects caller-selected state or credentials', function (): void {
    $url = '/api/v1/projects/'.$this->project->id.'/maintenance/routine';
    $this->postJson($url, [])->assertUnprocessable();
    foreach ([['groups' => []], ['repository' => 'other/repository'], ['token' => 'private-sentinel']] as $body) {
        $this->withHeader('Idempotency-Key', 'invalid-body')->postJson($url, $body)->assertUnprocessable();
    }
    expect($this->socket->sent)->toBe([])->and(Operation::query()->count())->toBe(0);
});

it('admits one group only after the bound terminal result and makes replay a noop', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'terminal-admission')->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/routine', [])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $result = ['project_id' => $this->project->id, 'requests' => [['action' => 'create_group', 'project_id' => $this->project->id,
        'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $this->project->id, 'repository' => $this->project->repository_url], 'targets' => []],
        'input' => ['title' => 'Routine', 'brief' => 'Swamp schedule: native-fixture', 'tasks' => [['title' => 'Routine', 'brief' => 'Maintain dependencies.', 'deliverables' => [['id' => 'review', 'type' => 'review', 'description' => 'Review the changes.']]]]],
    ]]];
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result];
    $projection = app(ProjectMaintenanceProjection::class);
    $projection->apply($operation, $data);
    $projection->apply($operation, $data);
    expect(Task::topLevel()->count())->toBe(1)->and(Task::topLevel()->first()->status->value)->toBe('todo')
        ->and($operation->fresh()->status->value)->toBe('succeeded')
        ->and($operation->fresh()->state['maintenance_group_id'])->toBe(Task::topLevel()->first()->id);
});

it('settles an early native failure without inventing a result or starting a group', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'native-failure')->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/routine', [])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'operation.native_failed', 'result' => []]);
    expect(Task::topLevel()->count())->toBe(0)->and($operation->fresh()->status->value)->toBe('failed');
});

it('projects bounded native PR evidence only while the admitted watch remains current', function (bool $changed): void {
    $url = 'https://github.com/nckrtl/launch/pull/17';
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Watching',
        'brief' => 'Existing maintenance.', 'status' => 'running', 'orchestration' => 'external',
        'watched_pr_url' => $url, 'watched_pr_number' => 17, 'watched_pr_state' => 'open']);
    $operation = Operation::query()->create(['kind' => 'project.observe', 'target_type' => 'project',
        'target_id' => $this->project->id, 'node_id' => 209, 'status' => 'running',
        'input' => ['project' => ['repository' => $this->project->repository_url, 'default_branch' => 'main', 'check' => 'composer check'],
            'groups' => [['id' => $group->id, 'watched_pr_state' => 'open']]],
        'state' => ['swamp_run_id' => '06bb3b9e-d877-49b0-8b6b-cf257e227c39']]);
    if ($changed) {
        $group->update(['watched_pr_state' => 'closed']);
    }
    $result = ['project_id' => $this->project->id, 'observations' => [['group_id' => $group->id, 'url' => $url, 'state' => 'merged']], 'requests' => []];
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
    expect($group->fresh()->watched_pr_state)->toBe($changed ? 'closed' : 'merged')
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($operation->fresh()->status->value)->toBe('succeeded');
})->with([false, true]);

it('rechecks current Project work before admitting two different captured requests', function (): void {
    $url = '/api/v1/projects/'.$this->project->id.'/maintenance/routine';
    $operations = [];
    foreach (['first', 'second'] as $key) {
        $id = $this->withHeader('Idempotency-Key', $key)->postJson($url, [])->assertAccepted()->json('data.id');
        $operations[] = Operation::findOrFail($id);
    }
    foreach ($operations as $index => $operation) {
        $result = ['project_id' => $this->project->id, 'requests' => [['action' => 'create_group', 'project_id' => $this->project->id,
            'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $this->project->id, 'repository' => $this->project->repository_url], 'targets' => []],
            'input' => ['title' => 'Routine', 'brief' => 'Swamp schedule: race-'.$index, 'tasks' => []],
        ]]];
        app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
    }
    expect(Task::topLevel()->count())->toBe(1)
        ->and($operations[1]->fresh()->result['admission_blocked'])->toBe('project_has_unfinished_work')
        ->and($operations[1]->fresh()->result['admitted_group_ids'])->toBe([]);
});

it('keeps a failed native scan failed even when its typed result was published', function (): void {
    $operation = Operation::query()->create(['kind' => 'project.detect', 'target_type' => 'project',
        'target_id' => $this->project->id, 'node_id' => 209, 'status' => 'running',
        'input' => [], 'state' => ['swamp_run_id' => '7bf9d0ac-cffd-4f8a-80d7-12b28ba31303']]);
    $result = ['project_id' => $this->project->id, 'status' => 'failed', 'error_code' => 'source_acquisition_failed', 'requests' => []];
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
    expect($operation->fresh()->status->value)->toBe('failed')
        ->and($operation->fresh()->error_code)->toBe('source_acquisition_failed')
        ->and(Task::topLevel()->count())->toBe(0);
});

it('does not admit work from a snapshot after the Project repository changes', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'changed-project')->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/routine', [])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $this->project->update(['repository_url' => 'https://github.com/nckrtl/another-project.git']);
    $result = ['project_id' => $this->project->id, 'requests' => [['action' => 'create_group', 'project_id' => $this->project->id,
        'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $this->project->id, 'repository' => $this->project->repository_url], 'targets' => []],
        'input' => ['title' => 'Routine', 'brief' => 'Swamp schedule: original-repository', 'tasks' => []],
    ]]];
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
    expect(Task::topLevel()->count())->toBe(0)
        ->and($operation->fresh()->result['admission_blocked'])->toBe('project_settings_changed');
});

it('retains prior maintenance capacity until PR and assistance evidence settle', function (array $prior, bool $blocked): void {
    $assistance = $prior['assistance_requested'] ?? false;
    unset($prior['assistance_requested']);
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Prior', 'brief' => 'Earlier maintenance.', 'orchestration' => 'external', ...$prior]);
    if ($assistance) {
        TaskAssistance::apply($group, AssistanceKind::RemovalFailure, reason: 'Cleanup remains unresolved.');
    }
    $id = $this->withHeader('Idempotency-Key', 'retained-capacity')->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/routine', [])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $result = ['project_id' => $this->project->id, 'requests' => [['action' => 'create_group', 'project_id' => $this->project->id,
        'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $this->project->id, 'repository' => $this->project->repository_url], 'targets' => []],
        'input' => ['title' => 'Next', 'brief' => 'Swamp schedule: next', 'tasks' => []],
    ]]];
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
    expect(Task::topLevel()->count())->toBe($blocked ? 1 : 2)
        ->and($operation->fresh()->result['admission_blocked'] ?? null)->toBe($blocked ? 'project_has_unfinished_work' : null);
})->with([
    'open PR' => [['status' => 'completed', 'pr_url' => 'https://github.com/nckrtl/launch/pull/1', 'watched_pr_state' => 'open'], true],
    'closed unmerged PR' => [['status' => 'completed', 'pr_url' => 'https://github.com/nckrtl/launch/pull/1', 'watched_pr_state' => 'closed'], true],
    'unresolved assistance' => [['status' => 'completed', 'assistance_requested' => true], true],
    'failed group' => [['status' => 'failed'], true],
    'merged completed group' => [['status' => 'completed', 'pr_url' => 'https://github.com/nckrtl/launch/pull/1', 'watched_pr_state' => 'merged'], false],
    'clean cancelled group' => [['status' => 'cancelled'], false],
]);

it('retries a capacity-blocked captured group only from a new terminal observation', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Waiting', 'brief' => 'Swamp schedule: capacity',
        'status' => 'todo', 'orchestration' => 'external', 'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $this->project->id], 'targets' => [], 'stage' => 'provision', 'admission_pending' => true]]);
    $operation = Operation::query()->create(['kind' => 'project.observe', 'target_type' => 'project', 'target_id' => $this->project->id,
        'node_id' => 209, 'status' => 'running', 'input' => ['project' => ['repository' => $this->project->repository_url, 'default_branch' => 'main', 'check' => 'composer check'],
            'groups' => [['id' => $group->id, 'maintenance' => $group->maintenance]]], 'state' => ['swamp_run_id' => '06bb3b9e-d877-49b0-8b6b-cf257e227c39']]);
    app(TaskExtensionState::class)->disable();
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['project_id' => $this->project->id, 'observations' => [], 'resume_group_ids' => [$group->id], 'requests' => []]];
    app(ProjectMaintenanceProjection::class)->apply($operation, $data);
    expect($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toContain('extension.disabled')
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'tasks.provision')->count())->toBe(0);
});
