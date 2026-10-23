<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetTestSsh;

// The Gateway's part of the release commands: the input it sends and how it reads each answer. The agent's Rust tests
// in apps/agent/src/commands/production_tests.rs prove what each command does to a release home.

/** @return array{RemoteProductionDeployment, FakeAgentCommandRunner, Instance} */
function release_commands(?Closure $configure = null): array
{
    $node = Node::query()->create(['name' => 'deployment-node', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.219', 'wireguard_ip' => '10.44.0.219', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Deployment', 'slug' => 'deployment', 'repository_url' => 'https://example.test/deployment.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production', 'environment' => 'production',
        'source_layout' => 'release', 'checkout_path' => '/home/orbit-app-1/releases/initial', 'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1', 'root' => 'public', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active',
    ]);
    $agents = new FakeAgentCommandRunner;
    if ($configure !== null) {
        $configure($agents);
    }
    $ssh = new ProductionSshExecutor(new AppDevFakeSshExecutor([]), new FleetTestSsh, new FleetTestSsh);
    $deployment = new RemoteProductionDeployment($ssh, $agents, app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class), static fn (): string => '20260911-a1');

    return [$deployment, $agents, $instance];
}

it('prepares a release of the branch from the home the Instance records', function (string $root, string $suffix, string $storage): void {
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.prepare-release', ['name' => '20260911-a1', 'commit' => str_repeat('a', 40)]));
    $instance->update(['root' => $root]);

    $release = $deployment->prepare($instance->refresh(), 'release');

    $input = $agents->calls[0]['input'];
    expect($release)->toEqual(new DeploymentRelease('20260911-a1', '/home/orbit-app-1/releases/20260911-a1', str_repeat('a', 40)))
        ->and($agents->commands())->toBe(['instances.prepare-release'])
        ->and(array_diff_key($input, ['git_read' => true]))->toBe([
            'repository' => 'https://example.test/deployment.git', 'user' => 'orbit-app-1', 'home' => '/home/orbit-app-1',
            'instance' => $instance->id, 'relative_root' => $root, 'application_suffix' => $suffix, 'served' => [],
            'name' => '20260911-a1', 'branch' => 'release', 'commit' => null, 'shared_paths' => [$storage],
        ]);
})->with(['root public' => ['public', '', 'storage'], 'nested Laravel' => ['server/web/public', '/server/web', 'server/web/storage']]);

describe('a release pinned to a commit', function (): void {
    it('asks for the commit and returns the release at it', function (): void {
        $commit = str_repeat('c', 40);
        [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.prepare-release', ['name' => '20260911-a1', 'commit' => $commit]));

        expect($deployment->prepare($instance, 'release', $commit)->commit)->toBe($commit)
            ->and($agents->calls[0]['input']['commit'])->toBe($commit);
    });

    it('refuses a commit the branch does not contain', function (): void {
        [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->refuse('instances.prepare-release', 'instances.commit_not_on_branch'));

        expect(fn () => $deployment->prepare($instance, 'release', str_repeat('d', 40)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('deployment.commit_not_on_branch'));
    });

    it('rejects an answer that names another commit', function (): void {
        [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.prepare-release', ['name' => '20260911-a1', 'commit' => str_repeat('a', 40)]));

        expect(fn () => $deployment->prepare($instance, 'release', str_repeat('c', 40)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('deployment.receipt_invalid'));
    });

    it('rejects a malformed commit before it asks the agent', function (string $commit): void {
        [$deployment, $agents, $instance] = release_commands();

        expect(fn () => $deployment->prepare($instance, 'release', $commit))->toThrow(InvalidArgumentException::class)
            ->and($agents->calls)->toBe([]);
    })->with(['branch name' => 'main', 'short SHA' => 'abc1234', 'injection' => str_repeat('a', 39).';']);
});

it('sends a GitHub App token only as Git read access for a covered repository', function (): void {
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.prepare-release', ['name' => '20260911-a1', 'commit' => str_repeat('a', 40)]));
    $instance->project->update(['repository_url' => 'git@github.com:acme/deployment.git']);
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/deployment/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_sentinel'], 201),
    ]);

    $deployment->prepare($instance->refresh(), 'release');

    // The runner marks that input as carrying a credential (NativeAgentCommandRunnerTest).
    $access = $agents->calls[0]['input']['git_read'];
    expect($access)->toBeInstanceOf(GitReadEnvironment::class)
        ->and(implode("\n", $access->variables))->toContain(base64_encode('x-access-token:ghs_sentinel'));
});

it('activates the recorded release and checks the answer', function (string $answered, bool $accepted): void {
    $release = new DeploymentRelease('20260911-a1', '/home/orbit-app-1/releases/20260911-a1', str_repeat('a', 40));
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.activate-release', ['name' => '20260911-a1', 'commit' => $answered]));

    if ($accepted) {
        expect($deployment->activate($instance, $release))->toBe($release);
    } else {
        expect(fn () => $deployment->activate($instance, $release))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('deployment.receipt_invalid'));
    }
    expect($agents->calls[0]['input'])->toMatchArray(['name' => '20260911-a1', 'commit' => str_repeat('a', 40)]);
})->with(['the recorded commit' => [str_repeat('a', 40), true], 'another commit' => [str_repeat('b', 40), false]]);

it('reads the selected release, or none, and a retained one by name', function (): void {
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer(
        'instances.inspect-release',
        static fn (array $input): array => ['release' => ($input['name'] ?? null) === null ? null : ['name' => $input['name'], 'commit' => str_repeat('a', 40)]],
    ));

    expect($deployment->selected($instance))->toBeNull()
        ->and($deployment->retained($instance, '20260101000000-a'))->toEqual(new DeploymentRelease('20260101000000-a', '/home/orbit-app-1/releases/20260101000000-a', str_repeat('a', 40)))
        ->and(array_column(array_column($agents->calls, 'input'), 'name'))->toBe(['20260101000000-a']);
});

it('rejects traversal before it asks the agent to inspect a release', function (): void {
    [$deployment, $agents, $instance] = release_commands();

    expect(fn () => $deployment->retained($instance, '../foreign'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('rollback.release_invalid'))
        ->and($agents->calls)->toBe([]);
});

it('lists the retained releases with the nullable selection', function (?string $selected): void {
    [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.list-releases', [
        'selected' => $selected,
        'releases' => [['name' => 'initial', 'commit' => str_repeat('a', 40)], ['name' => '20260101000000-a', 'commit' => str_repeat('b', 40)]],
    ]));

    expect($deployment->releases($instance))->toEqual(new DeploymentReleaseState(['20260101000000-a', 'initial'], $selected));
})->with(['selected' => 'initial', 'none selected' => null]);

it('rejects a listing that repeats a release or selects one it does not list', function (array $answer): void {
    [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.list-releases', $answer));

    expect(fn () => $deployment->releases($instance))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('deployment.receipt_invalid'));
})->with([
    'repeated release' => [['selected' => null, 'releases' => [['name' => 'initial', 'commit' => str_repeat('a', 40)], ['name' => 'initial', 'commit' => str_repeat('a', 40)]]]],
    'unlisted selection' => [['selected' => '20260101000000-a', 'releases' => [['name' => 'initial', 'commit' => str_repeat('a', 40)]]]],
    'short commit' => [['selected' => null, 'releases' => [['name' => 'initial', 'commit' => 'abc']]]],
]);

it('prunes to the retained count around the selected and previous releases', function (): void {
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instances.prune-releases', ['removed' => ['20260101000000-a'], 'in_use' => ['initial']]));
    $selected = new DeploymentRelease('20260104000000-d', '/home/orbit-app-1/releases/20260104000000-d', str_repeat('a', 40));
    $previous = new DeploymentRelease('20260103000000-c', '/home/orbit-app-1/releases/20260103000000-c', str_repeat('b', 40));

    expect($deployment->prune($instance, $selected, $previous))->toBe(['removed' => ['20260101000000-a'], 'in_use' => ['initial']])
        ->and($agents->calls[0]['input'])->toMatchArray(['limit' => DeploymentRelease::RETAINED_PER_HOME, 'selected' => '20260104000000-d', 'previous' => '20260103000000-c']);
});
