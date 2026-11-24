<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    SwampNodeFixture::ensure();
    $this->gateway = genericProjectNode('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->nick = genericProjectNode('nick', '10.44.1.8');
    $this->mini = genericProjectNode('mini', '10.44.1.11');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

function genericProjectNode(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
    ]));
}

/** @param  list<string>  $fields */
function genericProjectInvalid(TestResponse $response, array $fields): void
{
    $response->assertJsonPath('error.code', 'validation.failed');
    expect(array_keys($response->json('error.details')))->toEqualCanonicalizing($fields);
}

it('creates a generic Project about a Node without reading Git or preparing a checkout or Instance', function (): void {
    $this->mock(RepositoryDefaultBranchResolver::class)->shouldNotReceive('resolve', 'verify');
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');

    $data = $this->postJson('/api/v1/projects', ['slug' => 'mini', 'type' => 'generic', 'node_id' => $this->mini->id])
        ->assertCreated()
        ->json('data');

    expect($data)->toMatchArray([
        'slug' => 'mini', 'name' => 'mini', 'type' => 'generic', 'node_id' => $this->mini->id,
        'repository_url' => null, 'default_branch' => null, 'root' => null, 'task_check' => null,
    ]);
    $project = Project::query()->findOrFail($data['id']);
    expect($project->repository_identity)->toBeNull()
        ->and($project->source_facts_captured_at)->toBeNull()
        ->and($project->environmentTemplate)->toBeNull()
        ->and($project->instances()->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse();

    // A retry with the same identity returns the same Project; a generic Project without a Node is allowed.
    $this->postJson('/api/v1/projects', ['slug' => 'mini', 'type' => 'generic', 'node_id' => $this->mini->id])
        ->assertOk()->assertJsonPath('data.id', $project->id);
    $this->postJson('/api/v1/projects', ['slug' => 'notes', 'type' => 'generic'])
        ->assertCreated()->assertJsonPath('data.node_id', null);
    $this->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()->assertJsonPath('data.type', 'generic')->assertJsonPath('data.node_id', $this->mini->id);
});

it('refuses repository fields on a generic Project and a second Project about the same Node', function (): void {
    $this->postJson('/api/v1/projects', [
        'slug' => 'nick', 'type' => 'generic', 'repository_url' => 'https://github.com/acme/nick.git', 'root' => 'public',
        'default_branch' => 'main', 'source_access' => 'github_app', 'task_check' => 'true',
    ])->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['repository_url', 'root', 'default_branch', 'source_access', 'task_check']));
    $this->postJson('/api/v1/projects', ['slug' => 'site', 'type' => 'laravel-app', 'node_id' => $this->nick->id])
        ->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['node_id', 'repository_url', 'root']));
    $this->postJson('/api/v1/projects', ['slug' => 'nick', 'type' => 'generic', 'node_id' => 999])
        ->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['node_id']));

    $this->postJson('/api/v1/projects', ['slug' => 'nick', 'type' => 'generic', 'node_id' => $this->nick->id])->assertCreated();
    $this->postJson('/api/v1/projects', ['slug' => 'nick-two', 'type' => 'generic', 'node_id' => $this->nick->id])
        ->assertStatus(409)->assertJsonPath('error.code', 'project.node_conflict');
    $this->postJson('/api/v1/projects', ['slug' => 'nick', 'type' => 'generic', 'node_id' => $this->mini->id])
        ->assertStatus(409)->assertJsonPath('error.code', 'project.identity_conflict');
    expect(Project::query()->count())->toBe(1);
});

it('finds the Project of a Node by its stable ID after the Node is renamed', function (): void {
    $nick = $this->postJson('/api/v1/projects', ['slug' => 'nick', 'type' => 'generic', 'node_id' => $this->nick->id])->json('data.id');
    $mini = $this->postJson('/api/v1/projects', ['slug' => 'mini', 'type' => 'generic', 'node_id' => $this->mini->id])->json('data.id');

    $this->mini->forceFill(['name' => 'mac-mini'])->save();

    $found = $this->getJson("/api/v1/projects?node_id={$this->mini->id}")->assertOk()->json('data');
    expect($found)->toHaveCount(1)
        ->and($found[0])->toMatchArray(['id' => $mini, 'node_id' => $this->mini->id, 'node_name' => 'mac-mini', 'repository' => null, 'instance_count' => 0]);
    $this->getJson("/api/v1/projects?node_id={$this->nick->id}")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nick);
    $this->getJson('/api/v1/projects?node_id=999')->assertOk()->assertJsonCount(0, 'data');

    // The Project outlives the Node it is about.
    $this->nick->delete();
    expect(Project::query()->findOrFail($nick)->node_id)->toBeNull();
    $this->getJson('/api/v1/projects?node_id=mini')->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['node_id']));
});

it('lists and shows a generic Project to a caller that reaches its Node and hides it from others', function (): void {
    $mini = $this->postJson('/api/v1/projects', ['slug' => 'mini', 'type' => 'generic', 'node_id' => $this->mini->id])->json('data.id');
    $this->postJson('/api/v1/projects', ['slug' => 'nick', 'type' => 'generic', 'node_id' => $this->nick->id])->assertCreated();
    $laptop = genericProjectNode('laptop', '10.44.1.20');
    $laptop->accessibleNodes()->attach($this->mini->id);
    $this->withServerVariables(['REMOTE_ADDR' => $laptop->wireguard_ip]);

    $this->getJson('/api/v1/projects')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mini)->assertJsonPath('data.0.node_name', 'mini');
    $this->getJson("/api/v1/projects?node_id={$this->nick->id}")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/projects/{$mini}")->assertOk()->assertJsonPath('data.slug', 'mini');
    $nick = Project::query()->where('slug', 'nick')->sole();
    $this->getJson("/api/v1/projects/{$nick->id}")->assertForbidden();
});

it('refuses an Instance, a type conversion and repository fields for a generic Project before any write', function (): void {
    $development = containerDefaultNode();
    $project = $this->postJson('/api/v1/projects', ['slug' => 'mini', 'type' => 'generic', 'node_id' => $this->mini->id])->json('data.id');

    $this->postJson('/api/v1/instances', ['project_id' => $project, 'node_id' => $development->id, 'name' => 'default'])
        ->assertStatus(409)->assertJsonPath('error.code', 'project.repository_required');
    expect(Instance::query()->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse();

    $this->patchJson("/api/v1/projects/{$project}", ['type' => 'laravel-app'])
        ->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['type']));
    $this->patchJson("/api/v1/projects/{$project}", ['repository_url' => 'https://github.com/acme/mini.git', 'slug' => 'mac'])
        ->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['repository_url', 'slug']));
    $this->patchJson("/api/v1/projects/{$project}", ['name' => 'Mac mini'])
        ->assertOk()->assertJsonPath('data.name', 'Mac mini')->assertJsonPath('data.type', 'generic');
});

it('keeps creating repository-backed Projects and refuses converting one to generic', function (): void {
    $commit = str_repeat('a', 40);
    $this->mock(RepositoryDefaultBranchResolver::class)->shouldReceive('verify')->once()->andReturnNull();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(['head' => $commit, 'files' => [
        '.env.example' => null, 'artisan' => ['mode' => '100644', 'object' => $commit, 'content' => 'entry'],
        'composer.json' => ['mode' => '100644', 'object' => $commit, 'content' => '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}'],
    ]]);

    $data = $this->postJson('/api/v1/projects', [
        'slug' => 'site', 'type' => 'laravel-app', 'root' => 'public',
        'repository_url' => 'https://github.com/acme/site.git', 'default_branch' => 'main',
    ])->assertCreated()->json('data');

    expect($data)->toMatchArray(['type' => 'laravel-app', 'repository_url' => 'https://github.com/acme/site.git', 'default_branch' => 'main', 'root' => 'public', 'node_id' => null]);
    expect(Project::query()->findOrFail($data['id'])->repository_identity)->toBe('github.com/acme/site');
    $this->patchJson("/api/v1/projects/{$data['id']}", ['type' => 'generic'])
        ->assertUnprocessable()->tap(fn ($response) => genericProjectInvalid($response, ['type']));
    $this->postJson('/api/v1/projects', ['slug' => 'site', 'type' => 'generic'])
        ->assertStatus(409)->assertJsonPath('error.code', 'project.identity_conflict');
});
