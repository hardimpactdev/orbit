<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
});

/** A thread of the role in the group: the role has had its first turn. */
function agentThreadOf(Task $group, string $role): AgentThread
{
    return AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3', 'runtime_key' => 'instance:1', 'external_id' => (string) Str::uuid(),
        'role' => $role, 'model' => 'claude-opus-5-5', 'effort' => 'high', 'provider_instance' => 'claudeHigh']);
}

describe('the copy turn agent of a task group', function (): void {
    it('stores what task:create names and resolves the rest from the Project and the Gateway, with the source of each value', function (): void {
        $this->project->update(['task_reviewer_model' => 'claude-sonnet-5-5']);

        $response = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief',
            'implementer_model' => 'claude-fable-5-2', 'implementer_effort' => 'max', 'reviewer_provider' => 'claude'])->assertCreated();

        $group = Task::topLevel()->findOrFail($response->json('data.id'));
        expect($group->only(['implementer_provider', 'implementer_model', 'implementer_effort', 'reviewer_provider', 'reviewer_model', 'reviewer_effort']))->toBe([
            'implementer_provider' => null, 'implementer_model' => 'claude-fable-5-2', 'implementer_effort' => 'max',
            'reviewer_provider' => 'claude', 'reviewer_model' => null, 'reviewer_effort' => null,
        ])
            ->and($response->json('data.implementer_model'))->toBe('claude-fable-5-2')
            ->and($response->json('data.reviewer_model'))->toBeNull()
            ->and($response->json('data.resolved_agents'))->toBe([
                'implementer' => ['provider' => ['value' => 'claude', 'source' => 'gateway'], 'model' => ['value' => 'claude-fable-5-2', 'source' => 'group'],
                    'effort' => ['value' => 'max', 'source' => 'group']],
                'reviewer' => ['provider' => ['value' => 'claude', 'source' => 'group'], 'model' => ['value' => 'claude-sonnet-5-5', 'source' => 'project'],
                    'effort' => ['value' => 'high', 'source' => 'gateway']],
            ]);
    });

    it('stores no model for a group that names none: the Gateway\'s Claude defaults resolve, and no task VM label', function (): void {
        config(['orbit.tasks.implementer_model' => 'pi-model', 'orbit.tasks.reviewer_model' => 'pi-model']);

        $response = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief'])->assertCreated();

        expect(Task::topLevel()->findOrFail($response->json('data.id'))->only(['implementer_model', 'reviewer_model']))->toBe(['implementer_model' => null, 'reviewer_model' => null])
            ->and($response->json('data.resolved_agents.implementer.model'))->toBe(['value' => 'claude-opus-5-5', 'source' => 'gateway'])
            ->and($response->json('data.resolved_agents.implementer.effort'))->toBe(['value' => 'medium', 'source' => 'gateway'])
            ->and($response->json('data.resolved_agents.reviewer.effort'))->toBe(['value' => 'high', 'source' => 'gateway']);
    });

    it('refuses a provider, effort or model the copy cannot take', function (array $body, string $field): void {
        $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', ...$body])
            ->assertUnprocessable()->assertJsonValidationErrors($field, 'error.details');
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);
        $this->patchJson('/api/v1/tasks/'.$group->id, $body)->assertUnprocessable()->assertJsonValidationErrors($field, 'error.details');
        $this->patchJson('/api/v1/projects/'.$this->project->id, array_combine(array_map(static fn (string $key): string => 'task_'.$key, array_keys($body)), $body))
            ->assertUnprocessable()->assertJsonValidationErrors('task_'.$field, 'error.details');
    })->with([
        'a provider the copy has no instance of' => [['implementer_provider' => 'codex'], 'implementer_provider'],
        'an effort Claude Code has no instance for' => [['reviewer_effort' => 'minimal'], 'reviewer_effort'],
        'a model with a provider in it' => [['reviewer_model' => 'claudeAgent/claude-opus-5-5'], 'reviewer_model'],
    ]);

    it('changes and clears a role\'s values with task:update until the role\'s first thread, then refuses them with tasks.agent_settings_locked', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo,
            'implementer_model' => 'claude-fable-5-2', 'reviewer_effort' => 'max']);

        $this->patchJson('/api/v1/tasks/'.$group->id, ['implementer_model' => null, 'implementer_effort' => 'low', 'reviewer_effort' => 'xhigh'])->assertOk()
            ->assertJsonPath('data.implementer_effort', 'low')->assertJsonPath('data.resolved_agents.implementer.model.source', 'gateway');
        expect($group->refresh()->only(['implementer_model', 'implementer_effort', 'reviewer_effort']))
            ->toBe(['implementer_model' => null, 'implementer_effort' => 'low', 'reviewer_effort' => 'xhigh']);

        agentThreadOf($group, 'reviewer');
        $this->patchJson('/api/v1/tasks/'.$group->id, ['implementer_model' => 'claude-sonnet-5-5', 'reviewer_model' => 'claude-haiku-5-5'])
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_settings_locked')->assertJsonPath('error.details.role', 'reviewer');
        // A refusal changes nothing; the role without a thread still changes alone.
        expect($group->refresh()->implementer_model)->toBeNull();
        $this->patchJson('/api/v1/tasks/'.$group->id, ['implementer_model' => 'claude-sonnet-5-5'])->assertOk()->assertJsonPath('data.implementer_model', 'claude-sonnet-5-5');

        agentThreadOf($group, 'implementer');
        $this->patchJson('/api/v1/tasks/'.$group->id, ['implementer_effort' => null])
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_settings_locked')->assertJsonPath('error.details.role', 'implementer');
    });
});

describe('the Project default of the copy turn agent', function (): void {
    it('sets and clears the six defaults with project:update, shows them, and resolves a group from them', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);
        $defaults = ['task_implementer_provider' => 'claude', 'task_implementer_model' => 'claude-sonnet-5-5', 'task_implementer_effort' => 'low',
            'task_reviewer_provider' => 'claude', 'task_reviewer_model' => 'claude-haiku-5-5', 'task_reviewer_effort' => 'max'];

        $this->patchJson('/api/v1/projects/'.$this->project->id, $defaults)->assertOk();

        $shown = $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk();
        foreach ($defaults as $key => $value) {
            $shown->assertJsonPath('data.'.$key, $value);
        }
        $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()
            ->assertJsonPath('data.resolved_agents.reviewer.effort', ['value' => 'max', 'source' => 'project'])
            ->assertJsonPath('data.resolved_agents.implementer.model', ['value' => 'claude-sonnet-5-5', 'source' => 'project']);

        $this->patchJson('/api/v1/projects/'.$this->project->id, ['task_reviewer_effort' => null])->assertOk()->assertJsonPath('data.task_reviewer_effort', null);
        expect($this->project->refresh()->task_reviewer_model)->toBe('claude-haiku-5-5');
        $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()->assertJsonPath('data.resolved_agents.reviewer.effort', ['value' => 'high', 'source' => 'gateway']);
    });
});
