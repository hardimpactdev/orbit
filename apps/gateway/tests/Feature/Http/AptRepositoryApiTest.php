<?php

declare(strict_types=1);

use App\Actions\Tools\ManageToolPolicyAction;
use App\Domain\Tools\AptRepositoryProjection;
use App\Domain\Tools\ToolConvergeProjection;
use App\Domain\Tools\ToolIntentProjection;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TestOrbitHome;

uses(LazilyRefreshDatabase::class);

/** The apt repository lifecycle through the public API (docs/reference/tools.md#apt-repositories). */
beforeEach(function (): void {
    Queue::fake();
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $this->control = SwampNodeFixture::ensure();
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'test-host-key',
    ]));
    $this->control->accessibleNodes()->attach($this->node);
    ToolManagerRecord::query()->create(['node_id' => $this->node->id, 'name' => 'apt', 'status' => 'active']);
    $this->definition = ['uris' => ['https://packages.example.test/apt'], 'suites' => ['stable'], 'components' => ['main'],
        'signing_key' => ['url' => 'https://packages.example.test/key.asc', 'fingerprint' => str_repeat('A', 40)]];
});

function repositoryApi(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => test()->control->wireguard_ip])->json($method, '/api/v1/'.$uri, $body);
}

function repositoryAdd(string $name, array $definition): TestResponse
{
    return repositoryApi('POST', 'apt-repositories', ['node_id' => test()->node->id, 'name' => $name, 'definition' => $definition]);
}

/** Ends a repository Operation as its run reported it: the repository's outcome, with the whole run's success and cleanup. */
function repositoryRun(int $operationId, string $outcome, ?string $code = null): Operation
{
    $operation = Operation::query()->findOrFail($operationId);
    $operation->update(['state' => ['swamp_run_id' => 'run-'.$operationId]]);
    $succeeded = in_array($outcome, ['ready', 'removed'], true);

    return app(AptRepositoryProjection::class)->apply($operation, ['run_id' => 'run-'.$operationId, 'status' => $succeeded ? 'succeeded' : 'failed',
        'started_at' => now()->toAtomString(), 'finished_at' => now()->toAtomString(), 'result' => ['succeeded' => $succeeded, 'changed' => 1, 'tools' => [],
            'repositories' => [['name' => $operation->input['name'], 'status' => $outcome, 'error_code' => $code]], 'cleanup_complete' => true]]);
}

function repositoryTool(string $package, ?AptRepository $repository): Tool
{
    return Tool::query()->create(['node_id' => test()->node->id, 'tool_manager_id' => ToolManagerRecord::query()->where('node_id', test()->node->id)->value('id'),
        'package' => $package, 'desired_version' => '1.0', 'installed_version' => '1.0', 'status' => 'installed', 'owned' => true, 'apt_repository_id' => $repository?->id]);
}

it('adds a repository through an Operation that publishes it, and makes it usable only when that add succeeded', function (): void {
    $add = repositoryAdd('vendor', $this->definition)->assertAccepted()->assertJsonPath('data.kind', 'tool.apt_repository_add');
    $operation = Operation::query()->findOrFail($add->json('data.id'));
    $id = $operation->input['repository_id'];
    expect($operation->input['intent']['repositories'])->toBe([['id' => $id, 'repository' => ['name' => 'vendor', ...$this->definition], 'repository_packages' => []]])
        ->and($operation->input['intent']['tools'])->toBe([]);
    repositoryApi('GET', "apt-repositories/{$id}")->assertOk()->assertJsonPath('data.status', 'adding')->assertJsonPath('data.definition', $this->definition);

    repositoryRun($operation->id, 'ready');

    repositoryApi('GET', "apt-repositories?node_id={$this->node->id}")->assertOk()->assertJsonPath('data.0.name', 'vendor')->assertJsonPath('data.0.status', 'ready')
        ->assertJsonPath('data.0.error_code', null)->assertJsonPath('data.0.tool_count', 0);
    $install = repositoryApi('POST', 'tools', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'tree', 'repository' => 'vendor'])->assertAccepted();
    expect(Operation::query()->findOrFail($install->json('data.id'))->input)->toMatchArray(['repository' => ['name' => 'vendor', ...$this->definition], 'repository_id' => $id]);
});

it('records a failed add with its code, and an add of the same name again republishes it', function (): void {
    $first = repositoryAdd('vendor', $this->definition)->assertAccepted()->json('data.id');
    repositoryRun($first, 'failed', 'tool.repository_key_mismatch');
    $id = Operation::query()->findOrFail($first)->input['repository_id'];
    repositoryApi('GET', "apt-repositories/{$id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'tool.repository_key_mismatch');

    $again = Operation::query()->findOrFail(repositoryAdd('vendor', $this->definition)->assertAccepted()->json('data.id'));
    expect($again->input)->toMatchArray(['repository_id' => $id, 'previous' => ['status' => 'failed', 'error_code' => 'tool.repository_key_mismatch',
        'definition' => ['name' => 'vendor', ...$this->definition], 'operation_id' => $first]]);
    repositoryRun($again->id, 'ready');
    // A ready repository is published again too, with the owned members that reference it held to it.
    repositoryTool('tree', AptRepository::query()->findOrFail($id));
    $republish = Operation::query()->findOrFail(repositoryAdd('vendor', $this->definition)->assertAccepted()->json('data.id'));
    expect($republish->input['intent']['repositories'][0]['repository_packages'])->toBe(['tree']);
    repositoryRun($republish->id, 'ready');
    expect(AptRepository::query()->where('node_id', $this->node->id)->count())->toBe(1)
        ->and(AptRepository::query()->findOrFail($id)->ready())->toBeTrue();
});

it('refuses an add that shares a URI and suite with another repository, or redefines a repository Tools reference', function (): void {
    repositoryRun(repositoryAdd('vendor', $this->definition)->json('data.id'), 'ready');
    $vendor = AptRepository::query()->where('name', 'vendor')->sole();

    repositoryAdd('again', [...$this->definition, 'signing_key' => ['url' => 'https://other.example.test/key.asc', 'fingerprint' => str_repeat('B', 40)]])
        ->assertStatus(409)->assertJsonPath('error.code', 'tool.repository_conflict');
    repositoryTool('tree', $vendor);
    repositoryAdd('vendor', [...$this->definition, 'suites' => ['testing']])->assertStatus(409)->assertJsonPath('error.code', 'tool.repository_conflict');

    expect(Operation::query()->where('kind', 'tool.apt_repository_add')->count())->toBe(1)
        ->and(AptRepository::query()->pluck('name')->all())->toBe(['vendor'])
        ->and([$vendor->refresh()->definition['suites'], $vendor->ready()])->toBe([['stable'], true]);
});

it('refuses to remove a repository while a Tool references it, and leaves it after the last Tool goes', function (): void {
    repositoryRun(repositoryAdd('vendor', $this->definition)->json('data.id'), 'ready');
    $vendor = AptRepository::query()->where('name', 'vendor')->sole();
    $tree = repositoryTool('tree', $vendor);

    repositoryApi('DELETE', "apt-repositories/{$vendor->id}")->assertStatus(409)->assertJsonPath('error.code', 'tool.repository_in_use');
    // Removing the last Tool repins and removes the package, never the repository.
    $removal = Operation::query()->findOrFail(repositoryApi('DELETE', "tools/{$tree->id}")->assertAccepted()->json('data.id'));
    expect($removal->input)->toMatchArray(['repository_packages' => []])->not->toHaveKey('remove_repository');
    $removal->update(['state' => ['swamp_run_id' => 'remove-run']]);
    app(ToolIntentProjection::class)->apply($removal, ['run_id' => 'remove-run', 'status' => 'succeeded', 'started_at' => now()->toAtomString(), 'finished_at' => now()->toAtomString(),
        'result' => ['decision' => ['ready' => true, 'remove' => true], 'observation' => ['compliant' => true, 'absent' => true], 'steps' => ['Remove the package' => ['status' => 'succeeded'],
            'Reconcile remaining repository pins' => ['status' => 'succeeded'], 'Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded']]]]);
    expect($tree->fresh())->toBeNull()->and($vendor->fresh()?->ready())->toBeTrue();

    $remove = repositoryApi('DELETE', "apt-repositories/{$vendor->id}")->assertAccepted()->assertJsonPath('data.kind', 'tool.apt_repository_remove');
    expect(Operation::query()->findOrFail($remove->json('data.id'))->input['intent'])->toMatchArray(['repositories' => [], 'removed_repositories' => ['vendor']])
        ->and($vendor->refresh()->status->value)->toBe('removing');
    repositoryRun($remove->json('data.id'), 'removed');
    expect($vendor->fresh())->toBeNull();
});

it('refuses a Tool install, adoption or update that names a missing or not-ready repository', function (string $verb, string $state): void {
    if ($state === 'not ready') {
        repositoryRun(repositoryAdd('vendor', $this->definition)->json('data.id'), 'failed', 'tool.repository_unavailable');
    }
    $tree = repositoryTool('tree', null);
    $response = match ($verb) {
        'install' => repositoryApi('POST', 'tools', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'nano', 'repository' => 'vendor']),
        'adopt' => repositoryApi('POST', 'tools/adopt', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'nano', 'repository' => 'vendor']),
        'update' => repositoryApi('POST', "tools/{$tree->id}/update", ['repository' => 'vendor']),
    };

    $response->assertStatus($state === 'missing' ? 422 : 409)->assertJsonPath('error.code', $state === 'missing' ? 'tool.repository_missing' : 'tool.repository_not_ready');
    expect(Operation::query()->where('kind', 'like', 'tool.%')->whereNotIn('kind', ['tool.apt_repository_add'])->count())->toBe(0)
        ->and(Tool::query()->pluck('package')->all())->toBe(['tree'])->and($tree->refresh()->apt_repository_id)->toBeNull();
})->with(['install', 'adopt', 'update'])->with(['missing', 'not ready']);

it('publishes the catalog repository its enrolled Tools reference in a file-mode convergence, as that repository\'s own add', function (): void {
    $tree = repositoryTool('tree', null);
    $catalog = TestOrbitHome::scratch('tools.yaml');
    File::ensureDirectoryExists(dirname($catalog));
    file_put_contents($catalog, json_encode(['schema' => 1, 'repositories' => [['name' => 'vendor', ...$this->definition]],
        'packages' => [['manager' => 'apt', 'package' => 'tree', 'version' => '1.0']]], JSON_THROW_ON_ERROR));
    // Changing the mode needs Gateway authority; the convergence that follows is an ordinary Node request.
    app(ManageToolPolicyAction::class)->change('file', $catalog);
    // The catalog now has the Tool install from the repository: a direct write cannot change that, convergence publishes it.
    file_put_contents($catalog, json_encode(['schema' => 1, 'repositories' => [['name' => 'vendor', ...$this->definition]],
        'packages' => [['manager' => 'apt', 'package' => 'tree', 'version' => '1.0', 'repository' => 'vendor']]], JSON_THROW_ON_ERROR));
    $tree->update(['apt_repository_id' => null]);

    $converge = Operation::query()->findOrFail(repositoryApi('POST', "nodes/{$this->node->id}/tools/converge", [])->assertAccepted()->json('data.id'));
    $vendor = AptRepository::query()->where('node_id', $this->node->id)->where('name', 'vendor')->sole();
    expect($vendor->only(['status', 'error_code', 'operation_id']))->toMatchArray(['error_code' => 'tool.repository_unconfirmed', 'operation_id' => $converge->id])
        ->and($converge->input['intent']['repositories'])->toBe([['id' => $vendor->id, 'repository' => ['name' => 'vendor', ...$this->definition], 'repository_packages' => [], 'ready' => false]])
        ->and($converge->input['intent']['tools'][0])->toMatchArray(['id' => $tree->id, 'repository' => ['name' => 'vendor', ...$this->definition], 'repository_id' => $vendor->id]);

    $converge->update(['state' => ['swamp_run_id' => 'converge-run']]);
    app(ToolConvergeProjection::class)->apply($converge, ['run_id' => 'converge-run', 'status' => 'succeeded', 'started_at' => now()->toAtomString(), 'finished_at' => now()->toAtomString(),
        'result' => ['succeeded' => true, 'changed' => 2, 'tools' => [['id' => $tree->id, 'status' => 'installed', 'installed_version' => '1.0']],
            'repositories' => [['name' => 'vendor', 'status' => 'ready', 'error_code' => null]], 'cleanup_complete' => true]]);

    repositoryApi('GET', "apt-repositories/{$vendor->id}")->assertJsonPath('data.status', 'ready')->assertJsonPath('data.tool_count', 1);
    expect($tree->refresh()->apt_repository_id)->toBe($vendor->id);
});

it('keeps an upgraded repository with its definition and Tools, not ready until its own add', function (): void {
    $migration = require database_path('migrations/2027_03_28_000001_record_tool_ownership_and_apt_repository_status.php');
    $migration->down();
    $id = DB::table('apt_repositories')->insertGetId(['node_id' => $this->node->id, 'name' => 'vendor',
        'definition' => json_encode(['name' => 'vendor', ...$this->definition], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    $tree = DB::table('tools')->insertGetId(['node_id' => $this->node->id, 'tool_manager_id' => ToolManagerRecord::query()->value('id'), 'package' => 'tree',
        'status' => 'installed', 'installed_version' => '1.0', 'apt_repository_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();

    repositoryApi('GET', "apt-repositories/{$id}")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'tool.repository_unconfirmed')
        ->assertJsonPath('data.definition', $this->definition)->assertJsonPath('data.tool_count', 1);
    repositoryApi('POST', "tools/{$tree}/update", [])->assertStatus(409)->assertJsonPath('error.code', 'tool.repository_not_ready');

    // The one recovery: the operator adds it again with the definition it had.
    $add = Operation::query()->findOrFail(repositoryAdd('vendor', $this->definition)->assertAccepted()->json('data.id'));
    expect($add->input['repository_id'])->toBe($id);
    repositoryRun($add->id, 'ready');
    $update = Operation::query()->findOrFail(repositoryApi('POST', "tools/{$tree}/update", [])->assertAccepted()->json('data.id'));
    expect($update->input)->toMatchArray(['repository_id' => $id, 'repository' => ['name' => 'vendor', ...$this->definition]]);
});
