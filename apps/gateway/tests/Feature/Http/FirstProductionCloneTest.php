<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCloneProjection;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

it('admits the first production clone of a new Project from its sole container default', function (bool $web): void {
    Queue::fake();
    Http::fake(['api.github.com/repos/example/fresh-clone' => Http::response(['full_name' => 'example/fresh-clone', 'private' => false])]);
    Http::preventStrayRequests();
    SwampNodeFixture::ensure();
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $development = containerDefaultNode();
    $development->update(['tld' => 'dev.test']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $production = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'production', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '10.44.0.14', 'user' => 'orbit', 'tld' => 'prod.test',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $production->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $commit = str_repeat('a', 40);
    $this->mock(RepositoryDefaultBranchResolver::class)->shouldReceive('verify')->once()->andReturnNull();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn([
        'head' => $commit, 'files' => ['.env.example' => null,
            'artisan' => $web ? ['mode' => '100644', 'object' => $commit, 'content' => 'entry'] : null,
            'composer.json' => $web ? ['mode' => '100644', 'object' => $commit,
                'content' => '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}'] : null],
    ]);
    $project = $this->postJson('/api/v1/projects', [
        'slug' => 'fresh-clone', 'type' => $web ? 'laravel-app' : 'node-package', 'root' => $web ? 'public' : '.',
        'repository_url' => 'https://github.com/example/fresh-clone.git', 'default_branch' => 'main',
    ])->assertCreated()->json('data.id');

    $create = $this->postJson('/api/v1/instances', [
        'project_id' => $project, 'node_id' => $development->id, 'name' => 'default',
    ])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($create);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'create-run']])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), [
        'status' => 'succeeded', 'run_id' => 'create-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => $commit],
    ]);
    $instances = Project::query()->findOrFail($project)->instances();
    $candidate = $instances->sole();
    expect($candidate->name)->toBe('default')
        ->and($candidate->runtime)->not->toBeNull();

    $url = "/api/v1/instances/{$candidate->id}/clone";
    $input = ['node_id' => $production->id, 'name' => 'primary', 'preview_name' => 'preview'];
    $this->postJson($url, [...$input, 'sqlite_source_path' => '/srv/workspace/database.sqlite'])
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect($instances->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'instance.clone')->exists())->toBeFalse();

    $firstId = $this->postJson($url, $input, ['Idempotency-Key' => 'first-clone'])
        ->assertAccepted()->json('data.id');
    $this->postJson($url, $input, ['Idempotency-Key' => 'first-clone'])
        ->assertAccepted()->assertJsonPath('data.id', $firstId);

    $first = Operation::query()->findOrFail($firstId);
    $target = Instance::query()->findOrFail($first->target_id);
    expect($first->input['direct_provisioning']['candidate'])->toMatchArray([
        'node_id' => $development->id, 'checkout' => '/srv/workspace', 'branch' => 'main',
        'container' => ['project' => $candidate->runtime->incus_project, 'name' => $candidate->runtime->container_name, 'seed_commit' => $commit],
    ])->and($target->status)->toBe(InstanceState::Reserved)
        ->and($target->checkout_path)->toBe("/home/orbit-app-{$project}/releases/initial")
        ->and($target->runtime()->exists())->toBeFalse()
        ->and($target->routes()->count())->toBe($web ? 1 : 0);
    $route = $target->routes()->first();
    if ($web) {
        expect($route->domain)->toBe('preview.prod.test')
            ->and($route->status->value)->toBe('pending');
    }

    $first->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'clone-first']])->save();
    app(InstanceCloneProjection::class)->apply($first->refresh(), [
        'status' => 'failed', 'run_id' => 'clone-first', 'error_code' => 'instance.clone_candidate_source_invalid',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [],
    ]);
    expect($first->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($target->refresh()->status)->toBe(InstanceState::Reserved);

    $this->postJson($url, [...$input, 'preview_name' => 'other'])
        ->assertConflict()->assertJsonPath('error.code', 'instance.clone_retry_conflict');

    $retryId = $this->postJson($url, $input)->assertAccepted()->json('data.id');
    $retry = Operation::query()->findOrFail($retryId);
    expect($retry->id)->not->toBe($first->id)
        ->and($retry->target_id)->toBe($target->id)
        ->and($instances->count())->toBe(2);
    $retry->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'clone-retry']])->save();
    app(InstanceCloneProjection::class)->apply($retry->refresh(), [
        'status' => 'succeeded', 'run_id' => 'clone-retry',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $target->id, 'ready' => true, 'branch' => 'main', 'commit' => $commit,
            'php_version' => $web ? '8.5' : null, 'laravel' => $web, 'candidate_commit' => $commit, 'environment_keys' => ['APP_ENV', 'APP_DEBUG']],
    ]);
    $this->postJson($url, $input)->assertAccepted()->assertJsonPath('data.id', $retryId);
    expect($target->refresh()->status)->toBe(InstanceState::Active)
        ->and($target->starting_commit)->toBe($commit)
        ->and($candidate->refresh()->runtime->seed_commit)->toBe($commit)
        ->and($instances->count())->toBe(2);
    if ($web) {
        expect($target->routes()->sole()->id)->toBe($route->id)
            ->and($route->refresh()->status->value)->toBe('active');
    }
})->with(['web Project' => [true], 'package Project' => [false]]);
