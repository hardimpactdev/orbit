<?php

declare(strict_types=1);

use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\GitHub\GitReadEnvironment;
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
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProjectUpdate;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Orb101ProjectUpdateFixture;

beforeEach(function (): void {
    $this->fixture = Orb101ProjectUpdateFixture::bind($this);
});

describe('Project branch switch', function (): void {
    it('requests the branch switch and restoration for the same managed checkout', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('project.update-source', []);
        $this->app->instance(AgentCommandRunner::class, $agents);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);
        $instance = $this->fixture->defaultInstance;

        $mutator->switchDefaultBranch($instance, 'other');
        $mutator->restoreDefaultBranch($instance, 'main');

        expect($agents->calls)->toBe([
            ['node' => $instance->node->name, 'command' => 'project.update-source', 'input' => [
                'operation' => 'switch_branch', 'path' => $instance->checkout_path, 'branch' => 'other',
            ]],
            ['node' => $instance->node->name, 'command' => 'project.update-source', 'input' => [
                'operation' => 'restore_branch', 'path' => $instance->checkout_path, 'branch' => 'main',
            ]],
        ]);
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

    it('maps a repository preflight refusal without changing the Project', function (): void {
        $agents = new FakeAgentCommandRunner()->refuse('project.update-source', 'project.source_failed', ['step' => 'app-update-repository-preflight']);
        $this->app->instance(AgentCommandRunner::class, $agents);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        expect(fn () => $mutator->preflightRepository(
            [$this->fixture->defaultInstance], 'git@github.com:acme/site.git', 'https://github.com/acme/site.git',
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_preflight_failed');
        });
        expect($agents->calls)->toHaveCount(1)
            ->and($agents->calls[0]['input'])->toMatchArray([
                'operation' => 'preflight_repository', 'path' => $this->fixture->defaultInstance->checkout_path,
                'current' => 'git@github.com:acme/site.git', 'proposed' => 'https://github.com/acme/site.git',
            ])
            ->and($this->fixture->project->refresh()->repository_url)->toBe('git@github.com:acme/site.git');
    });

    it('runs remote origin updates only against checkout paths', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('project.update-source', []);
        $this->app->instance(AgentCommandRunner::class, $agents);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);
        $this->app->instance(ProjectUpdateSourceMutator::class, $mutator);

        $mutator->changeOrigins(
            [$this->fixture->defaultInstance],
            'git@github.com:acme/site.git',
            'https://github.com/acme/site.git',
            [],
        );

        expect(array_column($agents->calls, 'input'))->toBe([
            ['operation' => 'set_origin', 'path' => '/srv/orbit/apps/acme/default', 'url' => 'https://github.com/acme/site.git'],
        ]);
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
        $agents = new FakeAgentCommandRunner()->answer('project.update-source', []);
        $this->app->instance(AgentCommandRunner::class, $agents);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        $mutator->preflightRepository($workspaces, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git');
        $evidence = $mutator->changeOrigins($workspaces, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git', []);
        $mutator->restoreOrigins($evidence);

        expect(array_column($agents->calls, 'node'))->toBe(['tvm-0', 'tvm-1', 'tvm-0', 'tvm-1', 'tvm-0', 'tvm-1'])
            ->and(array_map(static fn (array $call): string => $call['input']['operation'], $agents->calls))
            ->toBe(['preflight_repository', 'preflight_repository', 'set_origin', 'set_origin', 'restore_origin', 'restore_origin'])
            ->and($agents->calls[0]['input']['git_read'])->toBeNull()
            ->and(array_column($evidence, 'instance_id'))->toBe([$workspaces[0]->id, $workspaces[1]->id]);
        // A retry with that evidence changes nothing again.
        expect($mutator->changeOrigins($workspaces, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git', $evidence))->toBe($evidence)
            ->and($agents->calls)->toHaveCount(6);
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
        $agents = new FakeAgentCommandRunner()->answer('project.update-source', []);
        $this->app->instance(AgentCommandRunner::class, $agents);

        $this->app->make(RemoteProjectUpdateSourceMutator::class)->restoreOrigins([[
            'path' => '/srv/orbit/apps/acme/default', 'previous_url' => 'git@github.com:acme/site.git',
            'current_url' => 'https://github.com/acme/site.git', 'mutated' => true,
        ]]);

        expect($agents->calls)->toHaveCount(1)
            ->and($agents->calls[0]['node'])->toBe($this->fixture->defaultInstance->node->name);
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

    it('records attempted changes before dispatch and restores them after a later refusal', function (): void {
        $checkouts = [];
        foreach (['first', 'second'] as $name) {
            $checkouts[] = Instance::query()->create([
                'project_id' => $this->fixture->project->id, 'node_id' => $this->fixture->node->id, 'name' => $name,
                'environment' => 'development', 'source_layout' => InstanceSourceLayout::Checkout->value,
                'checkout_path' => '/srv/orbit/apps/acme/'.$name, 'branch' => 'main', 'status' => InstanceState::Active,
            ]);
        }
        $recorded = [];
        $agents = new FakeAgentCommandRunner()->answer('project.update-source', function (array $input) use (&$recorded, $checkouts): array {
            if ($input['operation'] === 'set_origin') {
                $row = collect($recorded)->firstWhere('path', $input['path']);
                expect($row['attempted'])->toBeTrue()->and($row['mutated'])->toBeFalse();
                if ($input['path'] === $checkouts[1]->checkout_path) {
                    throw new AgentCommandRefused('project.update-source', 'project.source_failed', 'Origin refused.', ['step' => 'app-update-repository-origin']);
                }
            }

            return [];
        });
        $this->app->instance(AgentCommandRunner::class, $agents);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        expect(function () use ($mutator, $checkouts, &$recorded): void {
            $mutator->changeOrigins($checkouts, 'git@github.com:acme/site.git', 'https://github.com/acme/site.git', [], function (array $rows) use (&$recorded): void {
                $recorded = $rows;
            });
        })->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_origin_failed');
        });
        expect(array_column($recorded, 'mutated', 'instance_id'))->toBe([$checkouts[0]->id => true, $checkouts[1]->id => false]);

        $mutator->restoreOrigins($recorded);

        expect(array_column(array_column($agents->calls, 'input'), 'operation'))->toBe(['set_origin', 'set_origin', 'restore_origin', 'restore_origin']);
        foreach (array_slice($agents->calls, 2) as $index => $call) {
            expect($call['input'])->toBe([
                'operation' => 'restore_origin', 'path' => $checkouts[$index]->checkout_path, 'url' => 'git@github.com:acme/site.git',
            ]);
        }
    });

    it('still reads the proposed repository with a credential from an ordinary checkout', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('project.update-source', []);
        $this->app->instance(AgentCommandRunner::class, $agents);

        $this->app->make(RemoteProjectUpdateSourceMutator::class)->preflightRepository(
            [$this->fixture->defaultInstance], 'git@github.com:acme/site.git', 'https://github.com/acme/site.git',
        );

        expect($agents->calls)->toHaveCount(1)
            ->and($agents->calls[0]['input']['git_read'])->toBeInstanceOf(GitReadEnvironment::class);
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
