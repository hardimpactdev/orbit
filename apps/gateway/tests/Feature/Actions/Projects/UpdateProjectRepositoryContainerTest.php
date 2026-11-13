<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

const RepositoryOld = 'https://github.com/nckrtl/site.git';
const RepositoryNew = 'https://github.com/acme/site.git';

/** A Project with a default Instance on Beast, in a container with a seed, or on a host checkout. */
function repositoryUpdateDefault(bool $container): Instance
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'type' => ProjectType::NodePackage, 'repository_url' => RepositoryOld,
        'default_branch' => 'main', 'root' => '.', 'source_access' => ProjectSourceAccess::GitHubApp]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/srv/site/default', 'status' => InstanceState::Active]);
    if ($container) {
        app(AllocateInstanceBridgeAction::class)->execute($node);
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
            'power_state' => InstanceRuntimePowerState::Running, 'seed_commit' => str_repeat('c', 40)]);
    }

    return $instance;
}

function updateRepository(Project $project): Project
{
    return app(UpdateProjectAction::class)->execute($project, new UpdateProjectData(false, null, false, null, true, RepositoryNew, false, null, false, null));
}

it('changes the repository of a Project whose default runs in a container, checking its seed commit on the Gateway', function (): void {
    $instance = repositoryUpdateDefault(container: true);
    $reader = $this->mock(ProjectSourceReader::class);
    $reader->shouldReceive('read')->withSomeOfArgs(RepositoryNew, ProjectSourceAccess::GitHubApp, 'main')->andReturnUsing(
        static fn (string $url, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array => $commit === null
            ? ['head' => str_repeat('a', 40), 'files' => array_fill_keys($paths, null)]
            : ['head' => $commit, 'files' => []],
    );
    $this->mock(ProjectUpdateSourceMutator::class, function (MockInterface $sources): void {
        // The container's workspace holds no origin: no agent checkout step runs for it.
        $sources->shouldReceive('preflightRepository')->once()->with([], RepositoryOld, RepositoryNew);
        $sources->shouldReceive('changeOrigins')->once()->with([], RepositoryOld, RepositoryNew, [], Mockery::any())->andReturn([]);
        $sources->shouldNotReceive('restoreOrigins');
    });

    $updated = updateRepository($instance->project);

    expect($updated->repository_url)->toBe(RepositoryNew);
    $reader->shouldHaveReceived('read')->with(RepositoryNew, ProjectSourceAccess::GitHubApp, 'main', [], str_repeat('c', 40))->once();
});

it('refuses a repository that does not hold the container seed commit and changes nothing', function (): void {
    $instance = repositoryUpdateDefault(container: true);
    $reader = $this->mock(ProjectSourceReader::class);
    $reader->shouldReceive('read')->withSomeOfArgs(RepositoryNew, ProjectSourceAccess::GitHubApp, 'main')->andReturnUsing(
        static function (string $url, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array {
            if ($commit !== null) {
                // Another history, or a repository the Project's source access cannot read: the fetch of the commit fails.
                throw new ResourceOperationException('project.source_unavailable', 'The Gateway could not read the Project source at the requested revision.', 502);
            }

            return ['head' => str_repeat('a', 40), 'files' => array_fill_keys($paths, null)];
        },
    );
    $this->mock(ProjectUpdateSourceMutator::class, function (MockInterface $sources): void {
        $sources->shouldReceive('preflightRepository')->once()->with([], RepositoryOld, RepositoryNew);
        $sources->shouldNotReceive('changeOrigins');
    });

    expect(fn () => updateRepository($instance->project))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('project.repository_preflight_failed')
            ->and($exception->status)->toBe(409)
            ->and($exception->getMessage())->toBe('The proposed repository does not hold commit ['.str_repeat('c', 40).'] of container Instance [default]: '
                ."Orbit cannot read it with the Project's source access, or it has another history.");
    });
    expect($instance->project->refresh()->repository_url)->toBe(RepositoryOld);
});

it('refuses a repository Orbit cannot read before any preflight', function (): void {
    $instance = repositoryUpdateDefault(container: true);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()
        ->andThrow(new ResourceOperationException('project.source_unavailable', 'The Gateway could not read the Project source at the requested revision.', 502));
    $this->mock(ProjectUpdateSourceMutator::class)->shouldNotReceive('preflightRepository');

    expect(fn () => updateRepository($instance->project))->toThrow(ResourceOperationException::class, 'The Gateway could not read the Project source at the requested revision.');
    expect($instance->project->refresh()->repository_url)->toBe(RepositoryOld);
});

it('keeps the agent origin steps for a Project whose default is a host checkout', function (): void {
    $instance = repositoryUpdateDefault(container: false);
    $reader = $this->mock(ProjectSourceReader::class);
    $reader->shouldReceive('read')->once()->with(RepositoryNew, ProjectSourceAccess::GitHubApp, 'main', Mockery::any())
        ->andReturnUsing(static fn (string $url, ProjectSourceAccess $access, string $branch, array $paths): array => ['head' => str_repeat('a', 40), 'files' => array_fill_keys($paths, null)]);
    $this->mock(ProjectUpdateSourceMutator::class, function (MockInterface $sources) use ($instance): void {
        $matches = static fn (array $checkouts): bool => array_map(static fn (Instance $checkout): int => $checkout->id, $checkouts) === [$instance->id];
        $sources->shouldReceive('preflightRepository')->once()->with(Mockery::on($matches), RepositoryOld, RepositoryNew);
        $sources->shouldReceive('changeOrigins')->once()->with(Mockery::on($matches), RepositoryOld, RepositoryNew, [], Mockery::any())
            ->andReturn([['instance_id' => $instance->id, 'path' => '/srv/site/default', 'previous_url' => RepositoryOld, 'current_url' => RepositoryNew, 'mutated' => true, 'attempted' => true]]);
    });

    expect(updateRepository($instance->project)->repository_url)->toBe(RepositoryNew);
});
