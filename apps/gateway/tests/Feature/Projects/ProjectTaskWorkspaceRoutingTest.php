<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectUpdate;

beforeEach(function (): void {
    $this->operator = $this->markAsGateway(Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
});

it('stores true when creation omits routing and false when creation sends false', function (): void {
    $routed = $this->postJson('/api/v1/projects', routing_project_payload('orbit'))
        ->assertCreated()
        ->assertJsonPath('data.task_workspace_routed', true)
        ->assertJsonPath('data.slug', 'orbit');

    $unrouted = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('shop'),
        'task_workspace_routed' => false,
        'task_check' => 'composer check',
    ])->assertCreated()
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check');

    $this->getJson('/api/v1/projects/'.$unrouted->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.task_workspace_routed', false);

    $listed = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))->keyBy('slug');

    expect($listed['orbit']['task_workspace_routed'])->toBeTrue()
        ->and($listed['shop']['task_workspace_routed'])->toBeFalse()
        ->and(Project::query()->findOrFail($routed->json('data.id'))->task_workspace_routed)->toBeTrue()
        ->and(Project::query()->findOrFail($unrouted->json('data.id'))->task_workspace_routed)->toBeFalse();
});

it('keeps a stored value when a create retry omits routing and conflicts when the sent boolean differs', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('kept'),
        'task_workspace_routed' => false,
    ])->assertCreated()
        ->assertJsonPath('data.task_workspace_routed', false);

    $this->postJson('/api/v1/projects', routing_project_payload('kept'))
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.task_workspace_routed', false);

    $this->postJson('/api/v1/projects', [
        ...routing_project_payload('kept'),
        'task_workspace_routed' => false,
    ])->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'));

    $this->postJson('/api/v1/projects', [
        ...routing_project_payload('kept'),
        'task_workspace_routed' => true,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'project.identity_conflict');

    expect(Project::query()->where('slug', 'kept')->count())->toBe(1)
        ->and(Project::query()->where('slug', 'kept')->sole()->task_workspace_routed)->toBeFalse();
});

it('leaves routing unchanged when an update omits it, including a rename, and changes it when sent', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('rename-me'),
        'task_workspace_routed' => false,
        'task_check' => 'composer check',
    ])->assertCreated();
    $id = $created->json('data.id');

    $this->patchJson('/api/v1/projects/'.$id, ['code' => 'ZZZ'])
        ->assertOk()
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check');

    $this->patchJson('/api/v1/projects/'.$id, ['slug' => 'renamed'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'renamed')
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check');

    $this->patchJson('/api/v1/projects/'.$id, [
        'slug' => 'renamed-again',
        'task_workspace_routed' => true,
    ])->assertOk()
        ->assertJsonPath('data.slug', 'renamed-again')
        ->assertJsonPath('data.task_workspace_routed', true)
        ->assertJsonPath('data.task_check', 'composer check');

    expect(Project::query()->findOrFail($id)->task_workspace_routed)->toBeTrue()
        ->and(Project::query()->findOrFail($id)->taskCheckCommand())->toBe('composer check');
});

it('rejects null, strings, and numbers for task workspace routing', function (string $method, mixed $value): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('validated'),
        'task_workspace_routed' => false,
        'task_check' => 'composer check',
    ])->assertCreated();

    $payload = $method === 'post'
        ? [...routing_project_payload('rejected'), 'task_workspace_routed' => $value]
        : ['task_workspace_routed' => $value];
    $url = $method === 'post' ? '/api/v1/projects' : '/api/v1/projects/'.$created->json('data.id');

    $this->json($method, $url, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.task_workspace_routed.0', 'The task workspace routed field must be true or false.');

    expect(Project::query()->where('slug', 'rejected')->exists())->toBeFalse()
        ->and(Project::query()->findOrFail($created->json('data.id'))->task_workspace_routed)->toBeFalse()
        ->and(Project::query()->findOrFail($created->json('data.id'))->task_check)->toBe('composer check');
})->with([
    'create null' => ['post', null],
    'create string' => ['post', 'false'],
    'create numeric string' => ['post', '1'],
    'create zero' => ['post', 0],
    'create one' => ['post', 1],
    'update null' => ['patch', null],
    'update string' => ['patch', 'true'],
    'update numeric string' => ['patch', '0'],
    'update zero' => ['patch', 0],
    'update one' => ['patch', 1],
]);

it('does not reconcile sources when an update only changes task workspace routing', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('settings-only'),
        'task_check' => 'composer check',
    ])->assertCreated();
    $id = $created->json('data.id');
    $updates = ProjectUpdate::query()->count();

    $this->patchJson('/api/v1/projects/'.$id, ['task_workspace_routed' => false])
        ->assertOk()
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check')
        ->assertJsonPath('data.repository_url', 'https://github.com/acme/settings-only.git')
        ->assertJsonPath('data.slug', 'settings-only');

    expect(ProjectUpdate::query()->count())->toBe($updates)
        ->and(Activity::query()->latest('id')->first()?->properties['input'] ?? null)
        ->toBe(['task_workspace_routed' => false])
        ->and(Project::query()->findOrFail($id)->task_check)->toBe('composer check');
});

/**
 * @return array{slug: string, type: string, repository_url: string, default_branch: string, root: string}
 */
function routing_project_payload(string $slug): array
{
    return [
        'slug' => $slug,
        'type' => ProjectType::LaravelApp->value,
        'repository_url' => 'https://github.com/acme/'.$slug.'.git',
        'default_branch' => 'main',
        'root' => 'public',
    ];
}
