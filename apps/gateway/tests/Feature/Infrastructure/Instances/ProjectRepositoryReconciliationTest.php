<?php

declare(strict_types=1);

use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Projects\ProjectRepositoryUpdatePlanner;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Projects\ProjectUpdateStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use App\Infrastructure\Ssh\SshExecutor;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProjectUpdate;
use App\Models\Task;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\Orb101ProjectUpdateFixture;

beforeEach(function (): void {
    $this->fixture = Orb101ProjectUpdateFixture::bind($this);
});

describe('Project branch switch', function (): void {
    it('switches and restores Project branches as the managed user without hooks or credentials', function (): void {
        $checkout = sys_get_temp_dir().'/orbit-project-branch-'.bin2hex(random_bytes(6));
        $git = static fn (array $args): string => (new Process(['git', '-C', $checkout, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]))->mustRun()->getOutput();
        (new Process(['git', 'init', '-q', '-b', 'main', $checkout]))->mustRun();
        file_put_contents($checkout.'/.gitattributes', "readme filter=uid\n");
        file_put_contents($checkout.'/readme', 'main');
        $git(['add', '.']);
        $git(['commit', '-qm', 'main']);
        $git(['checkout', '-qb', 'other']);
        file_put_contents($checkout.'/readme', 'other');
        $git(['commit', '-qam', 'other']);
        $git(['remote', 'add', 'origin', $checkout]);
        $git(['update-ref', 'refs/remotes/origin/other', 'HEAD']);
        $git(['checkout', '-q', 'main']);
        foreach (['clean', 'smudge'] as $filter) {
            $git(['config', 'filter.uid.'.$filter, 'printf "%s:%s\\n" "$(id -u)" "${GIT_CONFIG_VALUE_0-absent}" >> .git/filter-users; cat']);
        }
        // A planted hook must never run.
        $git(['config', 'core.hooksPath', $checkout.'/.git/planted-hooks']);
        mkdir($checkout.'/.git/planted-hooks');
        file_put_contents($checkout.'/.git/planted-hooks/post-checkout', "#!/bin/sh\ntouch \"$(git rev-parse --git-dir)/hook-ran\"\n");
        chmod($checkout.'/.git/planted-hooks/post-checkout', 0755);
        @unlink($checkout.'/.git/filter-users');
        $this->fixture->defaultInstance->forceFill(['checkout_path' => $checkout])->save();
        $this->app->instance(SshExecutor::class, new LocalShellSshExecutor);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        try {
            $mutator->switchDefaultBranch($this->fixture->defaultInstance->refresh(), 'other');
            expect(file_get_contents($checkout.'/readme'))->toBe('other')
                ->and(trim($git(['config', '--get', 'branch.other.merge'])))->toBe('refs/heads/other')
                ->and(trim($git(['config', '--get', 'branch.other.remote'])))->toBe('origin');
            $mutator->restoreDefaultBranch($this->fixture->defaultInstance, 'main');
            expect(file_get_contents($checkout.'/readme'))->toBe('main');
            $users = file($checkout.'/.git/filter-users', FILE_IGNORE_NEW_LINES) ?: [];
            expect($users)->not->toBeEmpty();
            foreach ($users as $user) {
                expect($user)->toBe(posix_geteuid().':absent');
            }
            expect(fileowner($checkout.'/readme'))->toBe(posix_geteuid())
                ->and(file_exists($checkout.'/.git/hook-ran'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($checkout);
        }
    });
});

function orb101_repository_data(string $url): UpdateProjectData
{
    return new UpdateProjectData(
        typeProvided: false,
        type: null,
        slugProvided: false,
        slug: null,
        repositoryUrlProvided: true,
        repositoryUrl: $url,
        defaultBranchProvided: false,
        defaultBranch: null,
        rootProvided: false,
        root: null,
    );
}

describe('App repository reconciliation', function (): void {
    it('changes origin once per Orbit-owned checkout and never mutates a worktree common repository', function (): void {
        $worktree = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'feature',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/feature',
            'registration_common_repository_path' => '/srv/orbit/apps/acme/default',
            'branch' => 'feature',
            'status' => InstanceState::Active,
        ]);
        $planner = new ProjectRepositoryUpdatePlanner;
        $plan = $planner->inventory($this->fixture->project->instances()->get());

        expect($planner->uniqueCheckoutPaths($plan['checkouts']))
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($planner->ownedCheckout($plan['checkouts'], $worktree)?->id)
            ->toBe($this->fixture->defaultInstance->id);

        app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($this->fixture->sources->originMutations)
            ->not
            ->toContain('/srv/orbit/apps/acme/feature');
    });

    it('owns a worktree that Orbit created from a seed checkout and refuses a foreign seed', function (string $seed, bool $owned): void {
        $worktree = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'seeded',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/seeded',
            'seed_repository' => $seed,
            'seed_commit' => str_repeat('a', 40),
            'branch' => 'seeded',
            'status' => InstanceState::Active,
        ]);
        $planner = new ProjectRepositoryUpdatePlanner;
        $plan = $planner->inventory($this->fixture->project->instances()->get());

        expect($planner->ownedCheckout($plan['checkouts'], $worktree)?->id)
            ->toBe($owned ? $this->fixture->defaultInstance->id : null);

        $update = fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        );
        if ($owned) {
            $update();
            expect($this->fixture->sources->originMutations)->toBe(['/srv/orbit/apps/acme/default']);
        } else {
            expect($update)->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('project.repository_unowned_common');
            });
            expect($this->fixture->sources->originMutations)->toBe([]);
        }
    })->with([
        'seed is the default checkout' => ['/srv/orbit/apps/acme/default', true],
        'seed is another repository' => ['/unmanaged/repo', false],
    ]);

    it('refuses a worktree whose common repository no Orbit-owned checkout owns', function (): void {
        Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'orphan',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/orphan',
            'registration_common_repository_path' => '/unmanaged/repo',
            'branch' => 'main',
            'status' => InstanceState::Active,
        ]);

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_unowned_common');
        });

        expect($this->fixture->sources->originMutations)->toBe([]);
    });

    it('refuses before mutation when the complete source set cannot preflight', function (): void {
        $this->fixture->sources->refuseRepositoryPreflight = true;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_preflight_failed');
        });

        expect($this->fixture->project->refresh()->repository_url)
            ->toBe('git@github.com:acme/site.git')
            ->and($this->fixture->sources->originMutations)
            ->toBe([]);
    });

    it('preflights the configured origin despite an insteadOf rule and still refuses a different origin', function (): void {
        $sandbox = sys_get_temp_dir().'/orbit-app-update-origin-'.Str::uuid();
        $checkout = "{$sandbox}/checkout";
        $git = static function (array $arguments): void {
            $result = new NativeProcessRunner()->run(new ProcessInvocation(['git', ...$arguments]));
            expect($result->succeeded())->toBeTrue($result->stderr);
        };
        $git(['init', '--bare', '--initial-branch=main', "{$sandbox}/current.git"]);
        $git(['init', '--bare', '--initial-branch=main', "{$sandbox}/proposed.git"]);
        $git(['init', '--initial-branch=main', $checkout]);
        $git(['-C', $checkout, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--allow-empty', '-m', 'Initial']);
        $git(['-C', $checkout, 'remote', 'add', 'origin', "{$sandbox}/current.git"]);
        $git(['-C', $checkout, 'config', "url.{$sandbox}/./.insteadOf", "{$sandbox}/"]);
        $this->fixture->defaultInstance->update(['checkout_path' => $checkout]);
        $this->app->instance(SshExecutor::class, new LocalShellSshExecutor);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        try {
            $mutator->preflightRepository(
                [$this->fixture->defaultInstance->refresh()],
                "{$sandbox}/current.git",
                "{$sandbox}/proposed.git",
            );

            $git(['-C', $checkout, 'remote', 'set-url', 'origin', "{$sandbox}/other.git"]);

            expect(fn () => $mutator->preflightRepository(
                [$this->fixture->defaultInstance->refresh()],
                "{$sandbox}/current.git",
                "{$sandbox}/proposed.git",
            ))->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('project.repository_preflight_failed');
            });
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('runs remote origin updates only against checkout paths', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(0, '', '', 1, false),
            new CommandResult(0, 'https://github.com/acme/site.git', '', 1, false),
        ]);
        $this->app->instance(SshExecutor::class, $ssh);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);
        $this->app->instance(ProjectUpdateSourceMutator::class, $mutator);

        $mutator->changeOrigins(
            [$this->fixture->defaultInstance],
            'git@github.com:acme/site.git',
            'https://github.com/acme/site.git',
            [],
        );

        expect($ssh->commands)->toHaveCount(1);
        expect($ssh->commands[0]->arguments)
            ->toContain('/srv/orbit/apps/acme/default')
            ->toContain('https://github.com/acme/site.git')
            ->and($ssh->commands[0]->input)
            ->toContain('remote set-url origin')
            ->and($ssh->commands[0]->input)
            ->toContain('test ! -f "$path/.git"');
    });

    it('preflights, changes and restores task VM workspaces that share a path without a credential', function (): void {
        $workspaces = [];
        foreach (['10.44.0.131', '10.44.0.132'] as $index => $address) {
            $node = Node::query()->create(['name' => 'tvm-'.$index, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $address, 'wireguard_ip' => $address]);
            $group = Task::topLevel()->create(['project_id' => $this->fixture->project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'running']);
            test_task_vm($group, $node);
            $workspaces[] = Instance::query()->create([
                'project_id' => $this->fixture->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
                'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
                'checkout_path' => '/home/orbit/apps/acme/workspace', 'branch' => 'task-'.$group->id,
                'status' => InstanceState::Active, 'task_workspace_owner_id' => $group->id,
            ]);
        }
        $ssh = new AppDevFakeSshExecutor([
            ...array_fill(0, 2, new CommandResult(0, '', '', 1, false)),
            ...array_fill(0, 2, new CommandResult(0, 'https://github.com/acme/site.git', '', 1, false)),
        ]);
        $this->app->instance(SshExecutor::class, $ssh);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        $mutator->preflightRepository($workspaces, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git');
        $evidence = $mutator->changeOrigins($workspaces, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git', []);
        $mutator->restoreOrigins($evidence);

        expect($ssh->commands)->toHaveCount(6)
            ->and(array_map(static fn ($connection): string => $connection->host, $ssh->connections))
            ->toBe(['10.44.0.131', '10.44.0.132', '10.44.0.131', '10.44.0.132', '10.44.0.131', '10.44.0.132'])
            ->and(array_map(static fn ($command): ?bool => $command->protectedInput?->carriesGitCredential(), $ssh->commands))
            ->toBe(array_fill(0, 6, null))
            ->and($ssh->commands[0]->arguments)->toContain('0')
            ->and(array_column($evidence, 'instance_id'))->toBe([$workspaces[0]->id, $workspaces[1]->id]);
        // A retry with that evidence changes nothing again.
        expect($mutator->changeOrigins($workspaces, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git', $evidence))->toBe($evidence)
            ->and($ssh->commands)->toHaveCount(6);
    });

    it('reads the proposed repository on the Gateway for a task workspace and refuses a parked task VM', function (TaskVmState $state): void {
        Bus::fake([ResumeTaskVm::class]);
        $node = Node::query()->create(['name' => 'tvm-e2e', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.133', 'wireguard_ip' => '10.44.0.133']);
        $group = Task::topLevel()->create(['project_id' => $this->fixture->project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'settling']);
        test_task_vm($group, $node)->update(['state' => $state]);
        Instance::query()->create([
            'project_id' => $this->fixture->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
            'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/home/orbit/apps/acme/workspace', 'branch' => 'task-'.$group->id,
            'status' => InstanceState::Active, 'task_workspace_owner_id' => $group->id,
        ]);
        $branches = new class implements RepositoryDefaultBranchResolver
        {
            /** @var list<string> */
            public array $resolved = [];

            public function resolve(string $repository, ProjectSourceAccess $source): string
            {
                $this->resolved[] = $repository;

                return 'main';
            }

            public function verify(string $repository, string $branch, ProjectSourceAccess $source): void
            {
                $this->resolved[] = $repository.'@'.$branch;
            }
        };
        $this->app->instance(RepositoryDefaultBranchResolver::class, $branches);
        $update = fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        if ($state === TaskVmState::Parked) {
            expect($update)->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('project.task_vm_parked');
            });
            expect($branches->resolved)->toBe([])
                ->and($this->fixture->sources->originMutations)->toBe([])
                ->and($this->fixture->project->refresh()->repository_url)->toBe('git@github.com:acme/site.git');
            Bus::assertDispatched(ResumeTaskVm::class);
        } else {
            $update();
            expect($branches->resolved)->toBe(['https://github.com/acme/site.git@main'])
                ->and($this->fixture->sources->originMutations)->toContain('/home/orbit/apps/acme/workspace')
                ->and($this->fixture->project->refresh()->repository_url)->toBe('https://github.com/acme/site.git');
        }
    })->with([
        'ready' => [TaskVmState::Ready],
        'parked' => [TaskVmState::Parked],
    ]);

    it('restores origin evidence written before rows named their Instance by its path', function (): void {
        $ssh = new AppDevFakeSshExecutor;
        $this->app->instance(SshExecutor::class, $ssh);

        $this->app->make(RemoteProjectUpdateSourceMutator::class)->restoreOrigins([[
            'path' => '/srv/orbit/apps/acme/default', 'previous_url' => 'git@github.com:acme/site.git',
            'current_url' => 'https://github.com/acme/site.git', 'mutated' => true,
        ]]);

        expect($ssh->commands)->toHaveCount(1)
            ->and($ssh->connections[0]->host)->toBe($this->fixture->defaultInstance->node->wireguard_ip);
    });

    it('restores an earlier origin when a later checkout fails and records why the update rolled back', function (): void {
        $second = Instance::query()->create([
            'project_id' => $this->fixture->project->id, 'node_id' => $this->fixture->node->id, 'name' => 'second',
            'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/second', 'branch' => 'main', 'status' => InstanceState::Active,
        ]);
        $this->fixture->sources->failOriginChangeAt = 2;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_origin_failed');
        });

        $update = ProjectUpdate::query()->sole();
        expect($this->fixture->sources->originMutations)->toBe(['/srv/orbit/apps/acme/default'])
            ->and($this->fixture->sources->originRestores)->toBe(['/srv/orbit/apps/acme/default', $second->checkout_path])
            ->and(array_column($update->evidence['origins'], 'mutated', 'path'))
            ->toBe(['/srv/orbit/apps/acme/default' => true, $second->checkout_path => false])
            ->and($update->status)->toBe(ProjectUpdateStatus::RolledBack)
            ->and($this->fixture->project->refresh()->repository_url)->toBe('git@github.com:acme/site.git');
    });

    it('keeps a rollback open until its origins are restored, and finishes it on retry', function (): void {
        Instance::query()->create([
            'project_id' => $this->fixture->project->id, 'node_id' => $this->fixture->node->id, 'name' => 'second',
            'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/second', 'branch' => 'main', 'status' => InstanceState::Active,
        ]);
        $this->fixture->sources->failOriginChangeAt = 2;
        $this->fixture->sources->failOriginRestores = 1;
        $update = fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project->refresh(),
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect($update)->toThrow(ResourceOperationException::class);
        expect(ProjectUpdate::query()->sole()->status)->toBe(ProjectUpdateStatus::RollingBack)
            ->and($this->fixture->sources->originRestores)->toBe([]);

        $update();

        expect(ProjectUpdate::query()->sole()->status)->toBe(ProjectUpdateStatus::RolledBack)
            ->and($this->fixture->sources->originRestores)->toBe(['/srv/orbit/apps/acme/default', '/srv/orbit/apps/acme/second'])
            ->and($this->fixture->project->refresh()->repository_url)->toBe('git@github.com:acme/site.git');
    });

    it('restores a switched default branch when a later origin change fails', function (): void {
        Instance::query()->create([
            'project_id' => $this->fixture->project->id, 'node_id' => $this->fixture->node->id, 'name' => 'second',
            'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/second', 'branch' => 'main', 'status' => InstanceState::Active,
        ]);
        $this->fixture->sources->failOriginChangeAt = 2;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            new UpdateProjectData(
                typeProvided: false, type: null, slugProvided: false, slug: null,
                repositoryUrlProvided: true, repositoryUrl: 'https://github.com/acme/site.git',
                defaultBranchProvided: true, defaultBranch: 'stable', rootProvided: false, root: null,
            ),
        ))->toThrow(ResourceOperationException::class);

        expect($this->fixture->sources->switchedInstances)->toBe([$this->fixture->defaultInstance->id])
            ->and($this->fixture->sources->restoredBranches)->toBe([$this->fixture->defaultInstance->id])
            ->and($this->fixture->defaultInstance->refresh()->branch)->toBe('main')
            ->and(ProjectUpdate::query()->sole()->status)->toBe(ProjectUpdateStatus::RolledBack);
    });

    it('resumes a parked task VM before it restores its origin, and keeps the rollback open meanwhile', function (): void {
        Bus::fake([ResumeTaskVm::class]);
        $node = Node::query()->create(['name' => 'tvm-rollback', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.134', 'wireguard_ip' => '10.44.0.134']);
        $group = Task::topLevel()->create(['project_id' => $this->fixture->project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'settling']);
        $vm = test_task_vm($group, $node);
        Instance::query()->create([
            'project_id' => $this->fixture->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
            'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/home/orbit/apps/acme/workspace', 'branch' => 'task-'.$group->id,
            'status' => InstanceState::Active, 'task_workspace_owner_id' => $group->id,
        ]);
        $this->fixture->sources->failOriginChangeAt = 2;
        $this->fixture->sources->failOriginRestores = 1;
        $update = fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project->refresh(),
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect($update)->toThrow(ResourceOperationException::class);
        $vm->update(['state' => TaskVmState::Parked]);
        expect($update)->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.task_vm_parked');
        });
        expect(ProjectUpdate::query()->sole()->status)->toBe(ProjectUpdateStatus::RollingBack);
        Bus::assertDispatched(ResumeTaskVm::class);

        $vm->update(['state' => TaskVmState::Ready]);
        $update();

        expect(ProjectUpdate::query()->sole()->status)->toBe(ProjectUpdateStatus::RolledBack)
            ->and($this->fixture->sources->originRestores)->toContain('/home/orbit/apps/acme/workspace');
    });

    it('keeps the evidence of a real checkout that changed before a later one failed, and restores it', function (): void {
        $sandbox = sys_get_temp_dir().'/orbit-app-update-partial-'.Str::uuid();
        $git = static function (array $arguments): void {
            $result = new NativeProcessRunner()->run(new ProcessInvocation(['git', ...$arguments]));
            expect($result->succeeded())->toBeTrue($result->stderr);
        };
        $checkouts = [];
        foreach (['first', 'second'] as $name) {
            $git(['init', '--initial-branch=main', "{$sandbox}/{$name}"]);
            $git(['-C', "{$sandbox}/{$name}", 'remote', 'add', 'origin', 'git@github.com:acme/site.git']);
            $checkouts[] = Instance::query()->create([
                'project_id' => $this->fixture->project->id, 'node_id' => $this->fixture->node->id, 'name' => $name,
                'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
                'checkout_path' => "{$sandbox}/{$name}", 'branch' => 'main', 'status' => InstanceState::Active,
            ]);
        }
        // The second checkout cannot write its config, so its origin change fails after the first one changed.
        chmod("{$sandbox}/second/.git", 0555);
        $this->app->instance(SshExecutor::class, new LocalShellSshExecutor);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);
        $recorded = [];
        $origin = static fn (string $path): string => trim((string) shell_exec('git -C '.escapeshellarg($path).' config --get remote.origin.url'));

        try {
            $failure = null;
            try {
                $mutator->changeOrigins($checkouts, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git', [], function (array $rows) use (&$recorded): void {
                    $recorded = $rows;
                });
            } catch (ResourceOperationException $exception) {
                $failure = $exception->errorCode;
            }

            expect($failure)->toBe('project.repository_origin_failed');

            expect($origin("{$sandbox}/first"))->toBe('https://github.com/acme/site.git')
                ->and(array_column($recorded, 'mutated', 'instance_id'))->toBe([$checkouts[0]->id => true, $checkouts[1]->id => false]);

            // The second checkout never changed, so its restore writes nothing and passes while it stays read-only.
            $mutator->restoreOrigins($recorded);

            expect($origin("{$sandbox}/first"))->toBe('git@github.com:acme/site.git')
                ->and($origin("{$sandbox}/second"))->toBe('git@github.com:acme/site.git');
        } finally {
            @chmod("{$sandbox}/second/.git", 0755);
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('still reads the proposed repository with a credential from an ordinary checkout', function (): void {
        $ssh = new AppDevFakeSshExecutor;
        $this->app->instance(SshExecutor::class, $ssh);

        $this->app->make(RemoteProjectUpdateSourceMutator::class)->preflightRepository(
            [$this->fixture->defaultInstance], 'git@github.com:acme/site.git', 'https://github.com/acme/site.git',
        );

        expect($ssh->commands)->toHaveCount(1)
            ->and($ssh->commands[0]->arguments)->toContain('1')
            ->and($ssh->commands[0]->input)->toContain('ls-remote --heads');
    });

    it('re-binds every production home when the URL moves from SSH to HTTPS', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $https = 'https://github.com/acme/site.git';
        $record = new ProductionRepositoryRecord($ssh, $ssh, ['initial' => $ssh, '20261009-a' => $ssh]);
        $this->fixture->production->records[$production->id] = $record;

        app(UpdateProjectAction::class)->execute($this->fixture->project, orb101_repository_data($https));

        expect($this->fixture->project->refresh()->repository_url)->toBe($https)
            ->and($this->fixture->production->rebinds)->toEqual([[$production->id, $record, $record->withRepository($https)]])
            ->and($this->fixture->production->records[$production->id]->boundTo($https))->toBeTrue();
    });

    it('refuses a different repository while a production home records the old one', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $this->fixture->production->records[$production->id] = new ProductionRepositoryRecord($ssh, $ssh, ['initial' => $ssh]);

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/other.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.production_repository_mismatch');
        });

        expect($this->fixture->project->refresh()->repository_url)->toBe($ssh)
            ->and($this->fixture->production->rebinds)->toBe([])
            ->and($this->fixture->sources->originMutations)->toBe([]);
    });

    it('repairs a home that an earlier update left on the old URL when the current URL is sent again', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $https = 'https://github.com/acme/site.git';
        $this->fixture->project->update(['repository_url' => $https]);
        $record = new ProductionRepositoryRecord($ssh, $ssh, ['initial' => $ssh]);
        $this->fixture->production->records[$production->id] = $record;

        app(UpdateProjectAction::class)->execute($this->fixture->project->refresh(), orb101_repository_data($https));

        expect($this->fixture->production->rebinds)->toEqual([[$production->id, $record, $record->withRepository($https)]])
            ->and($this->fixture->sources->originMutations)->toBe([])
            ->and(ProjectUpdate::query()->latest('id')->first()?->status->value)->toBe('complete');

        // A home that already records the URL needs no rewrite.
        app(UpdateProjectAction::class)->execute($this->fixture->project->refresh(), orb101_repository_data($https));

        expect($this->fixture->production->rebinds)->toHaveCount(1);
    });

    it('restores production homes when the update rolls back', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $https = 'https://github.com/acme/site.git';
        $record = new ProductionRepositoryRecord($ssh, null, ['initial' => $ssh]);
        $this->fixture->production->records[$production->id] = $record;
        $this->fixture->production->failRebind = true;

        expect(fn () => app(UpdateProjectAction::class)->execute($this->fixture->project, orb101_repository_data($https)))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('project.production_rebind_failed');
            });

        expect($this->fixture->project->refresh()->repository_url)->toBe($ssh)
            ->and($this->fixture->production->rebinds)->toHaveCount(2)
            ->and($this->fixture->production->rebinds[1])->toEqual([$production->id, $record->withRepository($https), $record])
            ->and(ProjectUpdate::query()->latest('id')->first()?->status->value)->toBe('rolling_back');
    });
});

function orb101_production_instance(Orb101ProjectUpdateFixture $fixture): Instance
{
    $node = Node::query()->create([
        'name' => 'app-prod',
        'status' => 'active',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);

    return Instance::query()->create([
        'project_id' => $fixture->project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-72/releases/initial',
        'production_home' => '/home/orbit-app-72',
        'production_user' => 'orbit-app-72',
        'branch' => 'main',
        'status' => InstanceState::Active,
    ]);
}
