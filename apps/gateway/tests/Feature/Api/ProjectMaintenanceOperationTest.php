<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Projects\ProjectMaintenanceIntent;
use App\Domain\Projects\ProjectMaintenanceProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskStartCommit;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectInspection;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeTaskStartCommit;

beforeEach(function (): void {
    $node = $this->markAsGateway(Node::query()->create(['name' => 'project-maintenance-gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2']));
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp-209', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.15', 'public_ssh_host' => '192.0.2.209']);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'Launch', 'slug' => 'launch', 'repository_url' => 'https://github.com/nckrtl/launch.git', 'source_access' => 'github_app', 'task_check' => 'composer check', 'default_branch' => 'main', 'maintenance' => true]);
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
        // Swamp validates admitted_at as a Zulu ISO datetime; an offset fails the run's first step.
        ->and($operation->input['admitted_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(app(DirectOperation::class)->definition('project.routine')['terminal_result'])->toBe(['job' => 'routine', 'step' => 'Report the result']);
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

it('starts the admitted group provision after the projection commits, so its source read holds no transaction', function (): void {
    $start = new FakeTaskStartCommit;
    app()->instance(TaskStartCommit::class, $start);
    $id = $this->withHeader('Idempotency-Key', 'committed-admission')->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/routine', [])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $result = ['project_id' => $this->project->id, 'requests' => [['action' => 'create_group', 'project_id' => $this->project->id,
        'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $this->project->id, 'repository' => $this->project->repository_url], 'targets' => []],
        'input' => ['title' => 'Routine', 'brief' => 'Swamp schedule: native-fixture', 'tasks' => [['title' => 'Routine', 'brief' => 'Maintain dependencies.', 'deliverables' => [['id' => 'review', 'type' => 'review', 'description' => 'Review the changes.']]]]],
    ]]];
    $level = DB::transactionLevel();
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
    expect($start->transactionLevels)->toBe([$level]);
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
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => ['maintenance' => $result]]);
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
        'result' => ['maintenance' => ['project_id' => $this->project->id, 'observations' => [], 'resume_group_ids' => [$group->id], 'requests' => []]]];
    app(ProjectMaintenanceProjection::class)->apply($operation, $data);
    expect($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toContain('extension.disabled')
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'tasks.provision')->count())->toBe(0);
});

it('turns maintenance on and off for a Project that meets its requirements, and refuses admission while off', function (): void {
    $this->project->update(['maintenance' => false]);
    $url = '/api/v1/projects/'.$this->project->id.'/maintenance';
    $this->withHeader('Idempotency-Key', 'while-off')->postJson($url.'/routine', [])->assertConflict()->assertJsonPath('error.code', 'project.maintenance_disabled');
    expect(Operation::query()->count())->toBe(0);

    $this->postJson($url.'/enable')->assertOk()->assertJsonPath('data.maintenance', true);
    expect($this->project->fresh()->maintenance)->toBeTrue();
    $this->withHeader('Idempotency-Key', 'while-on')->postJson($url.'/routine', [])->assertAccepted();

    $this->postJson($url.'/disable')->assertOk()->assertJsonPath('data.maintenance', false);
    expect($this->project->fresh()->maintenance)->toBeFalse();
});

it('refuses to turn maintenance on without the GitHub App, a default branch or a task check', function (): void {
    $this->project->update(['maintenance' => false, 'source_access' => 'gh_cli', 'task_check' => null]);
    $this->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/enable')->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')->assertJsonPath('error.details.source_access.0', 'Dependency maintenance needs source access through the GitHub App.')
        ->assertJsonPath('error.details.task_check.0', 'Dependency maintenance needs a task check.')->assertJsonMissingPath('error.details.default_branch');
    expect($this->project->fresh()->maintenance)->toBeFalse();
});

describe('project:maintenance:inspect', function (): void {
    beforeEach(function (): void {
        GitHubTestSupport::storeApp();
        Http::fake(['*/repos/nckrtl/launch/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response(['token' => 'private-read-sentinel',
            'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read', 'metadata' => 'read'],
            'repositories' => [['full_name' => 'nckrtl/launch']]], 201), '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'nckrtl/launch']]])]);
        $this->socket->reply = static fn (array $sent): array => $sent['type'] === 'vault.put'
            ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
            : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'project-maintenance-inspect', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
        $this->manifests = ['[".","composer","composer"]' => ['composer.json' => str_repeat('1', 40), 'composer.lock' => str_repeat('2', 40)]];
        $this->sources = [['source' => ['path' => '.', 'ecosystem' => 'composer', 'package_manager' => 'composer'], 'packages' => [], 'declared' => ['php' => '^8.4']]];
    });

    it('starts a run with the last inspection and only a read token, and keeps the Operation small', function (bool $open, bool $due): void {
        ProjectInspection::query()->create(['project_id' => $this->project->id, 'source_commit' => str_repeat('a', 40), 'manifests' => $this->manifests,
            'sources' => $this->sources, 'targets' => [], 'blocked' => [], 'inspected_at' => now(), 'registry_checked_at' => now()->subHours(2)]);
        $id = $this->withHeader('Idempotency-Key', 'inspect-'.(int) $open)->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/inspect', ['open' => $open])
            ->assertAccepted()->assertJsonPath('data.kind', 'project.detect')->json('data.id');
        $start = json_decode(json_encode(collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        expect($start['workflowIdOrName'])->toBe('project-maintenance-inspect')
            ->and($start['inputs']['intent']['previous'])->toBe(['manifests' => $this->manifests, 'sources' => $this->sources])
            ->and($start['inputs']['intent']['check_due'])->toBe($due)
            ->and(array_keys($start['inputs']['execution_secrets']))->toBe(['github_authorization'])
            ->and(json_encode($start))->not->toContain('private-read-sentinel')
            ->and(Operation::findOrFail($id)->input['open'])->toBe($open)
            ->and($start['inputs']['intent']['repository'])->toBe('nckrtl/launch')
            ->and(Operation::findOrFail($id)->input)->not->toHaveKey('groups')
            ->and(json_encode(Operation::findOrFail($id)->input))->not->toContain('composer.lock');
    })->with(['inspect only' => [false, false], 'open the group' => [true, true]]);

    it('asks the registries again once the last check is a day old', function (): void {
        ProjectInspection::query()->create(['project_id' => $this->project->id, 'source_commit' => str_repeat('a', 40), 'manifests' => $this->manifests,
            'sources' => $this->sources, 'targets' => [], 'blocked' => [], 'inspected_at' => now(), 'registry_checked_at' => now()->subHours(25)]);
        $this->withHeader('Idempotency-Key', 'inspect-due')->postJson('/api/v1/projects/'.$this->project->id.'/maintenance/inspect')->assertAccepted();
        expect(collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload']['inputs']['intent']['check_due'])->toBeTrue();
    });

    it('stores what the run read and found, and admits the group only when asked to open', function (bool $open): void {
        $operation = Operation::query()->create(['kind' => 'project.detect', 'target_type' => 'project', 'target_id' => $this->project->id, 'node_id' => 209,
            'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => 'inspect-run'], 'input' => ['project' => ['id' => $this->project->id,
                'repository' => $this->project->repository_url, 'default_branch' => 'main', 'check' => 'composer check'], 'groups' => [], 'admitted_at' => now()->toIso8601ZuluString(), 'open' => $open]]);
        $target = ['path' => '.', 'manager' => 'composer', 'package' => 'laravel/framework', 'version' => '13.0.0', 'purpose' => 'major', 'advisories' => [], 'advisoryUrls' => []];
        $request = ['action' => 'create_group', 'project_id' => $this->project->id,
            'maintenance' => ['mode' => 'package', 'project' => ['projectId' => $this->project->id, 'repository' => $this->project->repository_url], 'targets' => [$target]],
            'input' => ['title' => 'Update', 'brief' => 'Swamp schedule: batch-fixture', 'tasks' => [['title' => 'Update', 'brief' => 'Update dependencies.', 'deliverables' => [['id' => 'review', 'type' => 'review', 'description' => 'Review the changes.']]]]]];
        $result = ['steps' => [], 'manifests' => ['commit' => str_repeat('c', 40), 'manifests' => $this->manifests, 'changed' => ['[".","composer","composer"]']],
            'sources' => $this->sources, 'registries' => '2026-11-03T10:00:00.000Z', 'updates' => ['targets' => [$target], 'blocked' => [], 'requests' => [$request]]];
        app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => 'inspect-run', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $result]);
        $inspection = ProjectInspection::query()->where('project_id', $this->project->id)->sole();
        expect($inspection->source_commit)->toBe(str_repeat('c', 40))->and($inspection->manifests)->toBe($this->manifests)
            ->and($inspection->sources)->toBe($this->sources)->and($inspection->targets)->toBe([$target])
            ->and($inspection->registry_checked_at?->toIso8601ZuluString())->toBe('2026-11-03T10:00:00Z')
            ->and($operation->fresh()->status->value)->toBe('succeeded')
            ->and($operation->fresh()->result)->toMatchArray(['source_commit' => str_repeat('c', 40), 'changed_sources' => 1, 'targets' => 1, 'blocked' => 0])
            ->and(json_encode($operation->fresh()->result))->not->toContain('declared')
            ->and(Task::topLevel()->count())->toBe($open ? 1 : 0);
    })->with(['inspect only' => [false], 'open the group' => [true]]);

    it('keeps the stored packages and findings when nothing changed and no check was due', function (): void {
        ProjectInspection::query()->create(['project_id' => $this->project->id, 'source_commit' => str_repeat('a', 40), 'manifests' => $this->manifests,
            'sources' => $this->sources, 'targets' => [['package' => 'kept']], 'blocked' => [], 'inspected_at' => now()->subHour(), 'registry_checked_at' => now()->subHours(2)]);
        $operation = Operation::query()->create(['kind' => 'project.detect', 'target_type' => 'project', 'target_id' => $this->project->id, 'node_id' => 209,
            'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => 'quiet-run'], 'input' => ['project' => ['id' => $this->project->id,
                'repository' => $this->project->repository_url, 'default_branch' => 'main', 'check' => 'composer check'], 'groups' => [], 'admitted_at' => now()->toIso8601ZuluString(), 'open' => true]]);
        app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => 'quiet-run', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => ['steps' => [], 'manifests' => ['commit' => str_repeat('b', 40), 'manifests' => $this->manifests, 'changed' => []]]]);
        $inspection = ProjectInspection::query()->sole();
        expect($inspection->source_commit)->toBe(str_repeat('b', 40))->and($inspection->sources)->toBe($this->sources)
            ->and($inspection->targets)->toBe([['package' => 'kept']])->and(Task::topLevel()->count())->toBe(0);
    });

    it('fails without changing the stored inspection when a step fails', function (): void {
        $operation = Operation::query()->create(['kind' => 'project.detect', 'target_type' => 'project', 'target_id' => $this->project->id, 'node_id' => 209,
            'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => 'failed-run'], 'input' => ['project' => ['id' => $this->project->id], 'open' => false]]);
        app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => 'failed-run', 'status' => 'failed', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'error_code' => 'swamp.step_failed', 'result' => []]);
        expect($operation->fresh()->status->value)->toBe('failed')->and(ProjectInspection::query()->count())->toBe(0);
    });
});

it('observes a Project without watched PRs without issuing a GitHub credential', function (): void {
    $operation = app(ProjectMaintenanceIntent::class)->start($this->project, 'observe');
    expect($operation->kind)->toBe('project.observe')
        ->and($operation->input['pr_urls'])->toBe([])
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['inputs']['execution_secrets'])->toBeObject();
});

it('refuses a foreign watched PR before issuing a credential or starting a run', function (): void {
    Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Wrong watch', 'brief' => 'Foreign pull request.',
        'status' => 'running', 'orchestration' => 'external', 'watched_pr_url' => 'https://github.com/other/project/pull/1']);
    expect(fn () => app(ProjectMaintenanceIntent::class)->start($this->project, 'observe'))
        ->toThrow(ResourceOperationException::class, 'A watched pull request must belong to this Project.');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('keeps a disabled Project unchanged when an earlier observation finishes', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Watching', 'brief' => 'Observe only while enabled.',
        'status' => 'running', 'orchestration' => 'external', 'watched_pr_url' => 'https://github.com/nckrtl/launch/pull/17', 'watched_pr_state' => 'open']);
    $operation = Operation::query()->create(['kind' => 'project.observe', 'target_type' => 'project', 'target_id' => $this->project->id,
        'node_id' => 209, 'status' => 'running', 'input' => ['project' => ['repository' => $this->project->repository_url, 'default_branch' => 'main', 'check' => 'composer check'],
            'groups' => [['id' => $group->id, 'watched_pr_state' => 'open']]], 'state' => ['swamp_run_id' => '06bb3b9e-d877-49b0-8b6b-cf257e227c39']]);
    $this->project->update(['maintenance' => false]);
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['maintenance' => ['project_id' => $this->project->id, 'observations' => [['group_id' => $group->id, 'url' => $group->watched_pr_url, 'state' => 'merged']], 'requests' => []]]]);
    expect($group->fresh()->watched_pr_state)->toBe('open')
        ->and($operation->fresh()->result['admission_blocked'])->toBe('project_settings_changed');
});

