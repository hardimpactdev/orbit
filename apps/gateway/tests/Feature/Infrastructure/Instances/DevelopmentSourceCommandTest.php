<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

describe('development source commands', function (): void {
    it('refuses the fixed workspace path on a Node that is not a task VM', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture('task-6');
        $instance->update(['checkout_path' => '/srv/orbit/apps/site/workspace']);

        expect(fn () => $source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class, 'unsafe checkout path');

        expect($agents->calls)->toBe([]);
    });

    it('requests preparation and inspection with the same source identity', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture();
        $agents->answer('instance.prepare-development-source', ['created' => true])
            ->answer('instance.inspect-development-source', ['origin' => 'https://example.test/site.git', 'branch' => 'dev', 'commit' => str_repeat('a', 40)]);

        $source->prepare($instance, false);
        $source->prepare($instance, true);
        $source->inspectPrepared($instance);
        $resolved = $source->inspectResolved($instance);

        expect($resolved->branch)->toBe('dev')->and($resolved->startingCommit)->toBe(str_repeat('a', 40));
        $identity = [
            'repository' => 'https://example.test/site.git', 'checkout' => '/srv/orbit/apps/site/dev',
            'root' => '/srv/orbit/apps', 'user' => 'orbit', 'group' => 'orbit',
            'prepare_id' => 'attempt-1', 'seed_repository' => null,
        ];
        expect($agents->commands())->toBe([
            'instance.prepare-development-source', 'instance.prepare-development-source',
            'instance.inspect-development-source', 'instance.inspect-development-source',
        ]);
        expect(array_column($agents->calls, 'input'))->toEqual([
            [...$identity, 'allow_existing' => false, 'seed_commit' => null, 'network' => true, 'git_read' => GitReadEnvironment::none()],
            [...$identity, 'allow_existing' => true, 'seed_commit' => null, 'network' => true, 'git_read' => GitReadEnvironment::none()],
            [...$identity, 'verify_origin' => true, 'require_idle' => false],
            [...$identity, 'verify_origin' => true, 'require_idle' => false],
        ]);
    });

    it('passes branch policy and accepts the selected branch and exact commit', function (string $name, ?string $override, string $selected): void {
        [$source, $agents, $instance] = developmentSourceFixture($name);
        $instance->update(['branch_override' => $override]);
        config(['orbit.git.checkout_protocols' => ['https', 'ssh']]);
        $agents->answer('instance.resolve-development-source', ['branch' => $selected, 'commit' => str_repeat('a', 40)]);

        $resolved = $source->resolve($instance);

        expect($resolved->branch)->toBe($selected)->and($resolved->startingCommit)->toBe(str_repeat('a', 40));
        expect($agents->calls[0]['input'])->toEqual([
            'repository' => 'https://example.test/site.git', 'checkout' => '/srv/orbit/apps/site/'.$name,
            'root' => '/srv/orbit/apps', 'user' => 'orbit', 'group' => 'orbit', 'prepare_id' => 'attempt-1', 'seed_repository' => null,
            'instance_name' => $name, 'default_branch' => 'main', 'branch_override' => $override, 'seed_commit' => null,
            'network' => true, 'protocols' => ['https', 'ssh'], 'git_read' => GitReadEnvironment::none(),
        ]);
    })->with([
        'default identity' => ['default', null, 'main'],
        'named branch' => ['dev', null, 'dev'],
        'task branch' => ['task-12', 'task-12', 'task-12'],
        'explicit branch on default' => ['default', 'dev', 'dev'],
        'explicit missing branch' => ['default', 'missing', 'missing'],
        'separate branch and Instance name' => ['t3-1234', 't3code/1234', 't3code/1234'],
    ]);

    it('persists the deployed seed before asking for a linked checkout', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture('task-12');
        Instance::query()->create([
            'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'default',
            'checkout_path' => '/srv/orbit/apps/site/default', 'source_layout' => 'checkout', 'status' => InstanceState::Active,
            'seed_commit' => str_repeat('d', 40), 'seed_path' => '/srv/orbit/apps/site/default',
        ]);
        $agents->answer('instance.prepare-development-source', function (array $input) use ($instance): array {
            expect($instance->fresh()->source_layout)->toBe('worktree')
                ->and($instance->fresh()->seed_selected)->toBeTrue();
            expect($input['seed_repository'])->toBe('/srv/orbit/apps/site/default')
                ->and($input['seed_commit'])->toBe(str_repeat('d', 40));

            return ['created' => true];
        });

        $source->prepare($instance, false);
    });

    it('refuses source resolution without a stored default branch before dispatch', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture();
        $instance->project->default_branch = null;

        expect(fn () => $source->resolve($instance))->toThrow(RuntimeConvergenceException::class);
        expect($agents->calls)->toBe([]);
    });

    it('refuses source results that disagree with branch intent or omit a commit', function (array $result): void {
        [$source, $agents, $instance] = developmentSourceFixture();
        $agents->answerMalformed('instance.resolve-development-source', $result);

        expect(fn () => $source->resolve($instance))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_identity_invalid');
        });
    })->with([
        'wrong branch' => [['branch' => 'other', 'commit' => str_repeat('a', 40)]],
        'invalid commit' => [['branch' => 'dev', 'commit' => 'invalid']],
        'missing commit' => [['branch' => 'dev']],
    ]);

    it('uses the read-only idle inspection for rename and compares canonical repository identity', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture();
        $agents->answer('instance.inspect-development-source', ['origin' => 'git@example.test:site.git', 'branch' => 't3code/login']);

        $source->assertBranchCheckedOut($instance, 't3code/login');
        expect(fn () => $source->assertBranchCheckedOut($instance, 'dev'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.branch_not_checked_out'));
        $agents->answer('instance.inspect-development-source', ['origin' => 'https://example.test/site.git', 'branch' => null]);
        expect(fn () => $source->assertBranchCheckedOut($instance, 'dev'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.branch_not_checked_out'));
        $source->assertBranchCheckedOut($instance, null);

        expect(array_unique($agents->commands()))->toBe(['instance.inspect-development-source']);
        expect($agents->calls[0]['input'])->toBe([
            'repository' => 'https://example.test/site.git', 'checkout' => '/srv/orbit/apps/site/dev',
            'root' => '/srv/orbit/apps', 'user' => 'orbit', 'group' => 'orbit', 'prepare_id' => 'attempt-1',
            'seed_repository' => null, 'verify_origin' => false, 'require_idle' => true,
        ]);
    });

    it('maps a busy rename inspection to lifecycle busy', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture();
        $agents->refuse('instance.inspect-development-source', 'instance.source_busy');

        expect(fn () => $source->assertBranchCheckedOut($instance, 'dev'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.lifecycle_busy'));
    });

    it('maps a preparation refusal without changing the reserved status', function (): void {
        [$source, $agents, $instance] = developmentSourceFixture();
        $agents->refuse('instance.prepare-development-source', 'instance.source_invalid');

        expect(fn () => $source->prepare($instance, false))
            ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('instance.clone_failed'));
        expect($instance->fresh()->status)->toBe(InstanceState::Reserved);
    });

    it('supplies the Gateway GitHub CLI token as typed read access or refuses before preparation', function (?string $token): void {
        app()->instance(GitHubCliToken::class, new readonly class($token) implements GitHubCliToken
        {
            public function __construct(private ?string $value) {}

            public function token(): string
            {
                return $this->value ?? throw new ResourceOperationException('github.cli_unauthenticated', 'No login.');
            }
        });
        [$source, $agents, $instance] = developmentSourceFixture();
        $instance->project->update(['repository_url' => 'git@github.com:acme/private.git', 'source_access' => ProjectSourceAccess::GhCli]);
        $agents->answer('instance.prepare-development-source', ['created' => true]);

        if ($token === null) {
            expect(fn () => $source->prepare($instance, false))
                ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('github.cli_unauthenticated'));
            expect($agents->calls)->toBe([]);

            return;
        }
        $source->prepare($instance, false);

        $read = $agents->calls[0]['input']['git_read'];
        expect($read)->toBeInstanceOf(GitReadEnvironment::class);
        expect(implode("\n", $read->variables))->toContain(base64_encode("x-access-token:{$token}"));
    })->with(['logged in' => ['gho_sentinel000000000000000000'], 'not logged in' => [null]]);
});

/** @return array{RemoteDevelopmentInstanceSourceLifecycle, FakeAgentCommandRunner, Instance} */
function developmentSourceFixture(string $name = 'dev'): array
{
    $node = Node::query()->create([
        'name' => 'app-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
    ]);
    $project = Project::query()->create([
        'name' => 'Site', 'slug' => 'site', 'repository_url' => 'https://example.test/site.git', 'default_branch' => 'main',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/site/'.$name, 'status' => InstanceState::Reserved, 'source_prepare_id' => 'attempt-1',
    ]);
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $agents = new FakeAgentCommandRunner;
    $source = new RemoteDevelopmentInstanceSourceLifecycle(
        app(DevelopmentSshExecutor::class), $agents, $accounts,
        new CheckoutRemovalBoundary(new ProtectedPathCatalog), app(RepositoryReadAccess::class), app(TaskVmGit::class),
    );

    return [$source, $agents, $instance];
}
