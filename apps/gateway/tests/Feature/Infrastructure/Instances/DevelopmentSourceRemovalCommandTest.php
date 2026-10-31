<?php

declare(strict_types=1);

use App\Actions\Instances\DestroyInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\LifecycleAgent;

describe('source removal commands', function (): void {
    it('accepts only the coordinator inventory after a recorded sibling completes', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture(true);
        $sibling = '/srv/orbit/apps/site/sibling';
        $extra = $sibling;
        $agents->answer('instance.inspect-source', static function (array $input) use (&$extra): array {
            $row = sourceRemovalEvidence($input['checkout'], true);
            if ($extra !== null) {
                $row['worktrees'] = base64_encode(base64_decode($row['worktrees'])."worktree {$extra}\0HEAD ".str_repeat('a', 40)."\0branch refs/heads/sibling\0\0");
            }

            return $row;
        })->answer('instance.read-source-removal-state', ['state' => 'present']);
        $member = sourceRemovalRecord($removal, $instance, false);
        $originalDigest = $member->source_digest;
        $extra = null;
        $remaining = ['/srv/orbit/apps/site/default', $instance->checkout_path];
        $expectation = new InstanceSourceRevalidationExpectation($remaining, $remaining);

        expect(fn () => $removal->revalidate($member))->toThrow(RuntimeConvergenceException::class, 'inventory changed');
        expect($removal->revalidate($member, $expectation))->toBe(InstanceSourceRevalidationState::Present);
        $extra = '/srv/orbit/apps/site/unknown';
        expect(fn () => $removal->revalidate($member, $expectation))->toThrow(RuntimeConvergenceException::class, 'inventory changed');
        $extra = null;
        $agents->answer('instance.finalize-source-removal', static function (array $input) use ($instance, $originalDigest): array {
            expect($input['digest'])->toBe($originalDigest);
            expect($input['independent_worktree'])->toBeFalse();
            expect($input['worktrees'])->toBe(sourceRemovalEvidence($instance->checkout_path, true)['worktrees']);

            return ['state' => 'finalized', 'receipt' => $input['receipt']];
        });

        expect($removal->finalize($member, $expectation))->toBeString();
        expect($member->fresh()->source_digest)->toBe($originalDigest);
        expect($member->fresh()->linked_worktree_paths)->toContain($sibling);
    });

    it('refuses invalid stored placement or incomplete active identity before dispatch', function (array $change): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $instance->update($change);

        expect(fn () => $removal->inspect($instance, true))->toThrow(RuntimeConvergenceException::class);
        expect($agents->calls)->toBe([]);
    })->with([
        'outside the Instance directory' => [['checkout_path' => '/srv/unrelated']],
        'missing starting commit' => [['starting_commit' => null]],
    ]);

    it('sends an empty branch for direct removal of a detached checkout', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $instance->update(['branch' => null]);
        $agents->answer('instance.inspect-source', static function (array $input): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['branch'] = '';

            return $row;
        })->answer('instance.remove-source', []);

        $inventory = $removal->inspect($instance, true);
        $removal->remove($instance, $inventory, true);

        expect($inventory->branch)->toBeNull();
        expect($agents->calls[1]['input']['branch'])->toBe('');
    });

    it('uses the observed HEAD for publication and direct deletion', function (bool $force): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $agents->answer('instance.inspect-source', static function (array $input): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['commit'] = base64_encode(str_repeat('b', 40));
            $row['worktrees'] = base64_encode(str_replace(str_repeat('a', 40), str_repeat('b', 40), base64_decode($row['worktrees'])));

            return $row;
        })->answer('instance.check-publication', static function (array $input): array {
            expect($input['commit'])->toBe(str_repeat('b', 40));

            return ['published' => true];
        })->answer('instance.remove-source', []);

        $inventory = $removal->inspect($instance, $force);
        $removal->remove($instance, $inventory, $force);

        expect($instance->fresh()->starting_commit)->toBe(str_repeat('a', 40));
        expect($agents->calls[array_key_last($agents->calls)]['input']['commit'])->toBe(str_repeat('b', 40));
    })->with([false, true]);

    it('passes inspected identity and force policy to deletion', function (bool $force): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $agents->answer('instance.remove-source', static function (array $input, Node $node) use ($lock, $force): array {
            expect($lock->held)->toBe($node->id);
            expect($input['git_read']->isEmpty())->toBeTrue();
            expect($input['git_read']->githubToken())->toBeNull();
            unset($input['git_read']);
            expect($input)->toBe([
                'checkout' => '/srv/orbit/apps/site/dev', 'root' => '/srv/orbit/apps',
                'grouping_directory' => '/srv/orbit/apps/site', 'user' => 'orbit', 'group' => 'orbit',
                'branch' => 'dev', 'commit' => str_repeat('a', 40), 'source_identity' => '12:34',
                'repository_identity' => 'example.test/site', 'force' => $force, 'own_units' => [],
            ]);

            return [];
        });

        $inventory = $removal->inspect($instance, $force);
        $removal->remove($instance, $inventory, $force);

        expect($agents->calls[0]['input']['inspect_content'])->toBe(! $force);
        expect($agents->commands())->toBe($force
            ? ['instance.inspect-source', 'instance.remove-source']
            : ['instance.inspect-source', 'instance.check-publication', 'instance.remove-source']);
        expect($lock->held)->toBeNull();
    })->with([false, true]);

    it('maps named identity refusals without exposing paths or repository details', function (string $kind, bool $force): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $inventory = $removal->inspect($instance, $force);
        foreach (['instance.inspect-source', 'instance.remove-source'] as $command) {
            $agents->refuse($command, 'instance.'.$kind.'_mismatch');
            $agents->calls = [];

            expect(fn () => $command === 'instance.inspect-source'
                ? $removal->inspect($instance, $force)
                : $removal->remove($instance, $inventory, $force))
                ->toThrow(function (RuntimeConvergenceException $exception) use ($kind): void {
                    expect($exception->errorCode)->toBe('instance.source_'.$kind.'_mismatch');
                    expect($exception->getMessage())->toStartWith('Instance [dev] source ')
                        ->not->toContain('/srv/', 'example.test');
                });
            expect($agents->commands())->toBe([$command]);
        }
    })->with(['path', 'ownership', 'layout', 'origin', 'branch', 'worktrees'])->with([false, true]);

    it('compares canonical repository identities in inspection evidence', function (string $origin, bool $equivalent): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $agents->answer('instance.inspect-source', static function (array $input) use ($origin): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['origin'] = base64_encode($origin);

            return $row;
        });

        if ($equivalent) {
            expect($removal->inspect($instance, true)->repositoryIdentity)->toBe('example.test/site');
        } else {
            expect(fn () => $removal->inspect($instance, true))->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
            });
        }
        expect($agents->commands())->toBe(['instance.inspect-source']);
    })->with([
        'equivalent protocol' => ['ssh://git@example.test/site.git', true],
        'different repository' => ['https://example.test/other.git', false],
        'trailing linefeed' => ["https://example.test/site.git\n", false],
    ]);

    it('passes managed units to deletion regardless of task names or workspace ownership', function (string $kind, bool $recorded): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $name = $kind === 'ordinary' ? 'dev' : 'task-999999';
        $group = null;
        if (in_array($kind, ['other-node', 'unowned', 'owned'], true)) {
            $group = Task::topLevel()->create([
                'project_id' => $instance->project_id, 'title' => 'Workspace', 'brief' => 'Brief.', 'status' => TaskGroupStatus::Running,
            ]);
            $name = 'task-'.$group->id;
            $vmNode = $kind === 'other-node' ? Node::query()->create([
                'name' => 'separate-vm', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.131', 'wireguard_ip' => '10.44.0.131',
            ]) : $instance->node;
            TaskVm::query()->create([
                'group_id' => $group->id, 'node_id' => $vmNode->id, 'host_node_id' => $instance->node_id,
                'provider' => 'incus', 'name' => 'removal-vm', 'wireguard_ip' => '10.44.0.132', 'state' => TaskVmState::Ready,
            ]);
        }
        if ($kind === 'owned') {
            $instance = Instance::query()->create([
                'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
                'name' => $name, 'checkout_path' => '/srv/orbit/apps/site/'.$name,
                'task_workspace_owner_id' => $group?->id, 'source_layout' => 'checkout',
                'branch' => 'dev', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::Active,
            ]);
        } else {
            $instance->update(['name' => $name, 'checkout_path' => '/srv/orbit/apps/site/'.$name]);
        }
        $process = $instance->processes()->create([
            'name' => 'web', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['sleep', '1']],
            'working_directory' => $instance->checkout_path, 'restart_policy' => 'on-failure',
            'desired_state' => 'running', 'status' => 'active',
        ]);
        $schedule = $instance->schedules()->create([
            'host_node_id' => $instance->node_id, 'name' => 'nightly', 'calendar' => 'daily',
            'command' => 'true', 'timeout_seconds' => 60, 'desired_timer_state' => DesiredTimerState::Enabled,
            'status' => LifecycleStatus::Active,
        ]);
        $expected = ["orbit-process-{$process->id}-web.service", "orbit-schedule-{$schedule->id}.service"];
        sort($expected);
        $agents->answer('instance.remove-source', [])
            ->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        if ($recorded) {
            $removal->finalize(sourceRemovalRecord($removal, $instance));
        } else {
            $removal->remove($instance, $removal->inspect($instance, true), true);
        }

        $last = $agents->calls[array_key_last($agents->calls)];
        expect($last['command'])->toBe($recorded ? 'instance.finalize-source-removal' : 'instance.remove-source');
        expect($last['input']['own_units'])->toBe($expected);
    })->with(['ordinary', 'task-name', 'other-node', 'unowned', 'owned'])->with([false, true]);

    it('refuses dirty or unpublished inspection evidence unless force is explicit', function (bool $dirty): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $agents->answer('instance.inspect-source', static function (array $input) use ($dirty): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['dirty'] = base64_encode($dirty ? '1' : '0');

            return $row;
        })->answer('instance.check-publication', ['published' => false]);

        expect(fn () => $removal->inspect($instance, false))->toThrow(RuntimeConvergenceException::class);
        expect($agents->commands())->not->toContain('instance.remove-source');
        $agents->calls = [];
        expect($removal->inspect($instance, true)->checkoutPath)->toBe($instance->checkout_path);
        expect($agents->commands())->toBe(['instance.inspect-source']);
    })->with([false, true]);

    it('returns linked checkout inventory and refuses direct removal before dispatch', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $agents->answer('instance.inspect-source', static function (array $input): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['worktrees'] = base64_encode(base64_decode($row['worktrees'])."worktree /srv/orbit/apps/site/sibling\0HEAD ".str_repeat('b', 40)."\0branch refs/heads/sibling\0\0");

            return $row;
        });

        $inventory = $removal->inspect($instance, true);

        expect($inventory->linkedWorktreePaths)->toBe([$instance->checkout_path, '/srv/orbit/apps/site/sibling']);
        expect(fn () => $removal->remove($instance, $inventory, true))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_worktrees_mismatch');
        });
        expect($agents->commands())->toBe(['instance.inspect-source']);
    });
});

describe('failed creation source removal', function (): void {
    it('lets force resume a lost normal worktree finalization without repeating runtime cleanup', function (): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture(true);
        $member = sourceRemovalRecord($removal, $instance, false);
        $state = 'present';
        $agents->answer('instance.read-source-removal-state', static function () use (&$state): array {
            return ['state' => $state];
        });
        $attempt = 0;
        $agents->answer('instance.finalize-source-removal', static function (array $input) use (&$attempt, &$state): array {
            if (++$attempt === 1) {
                expect($input['force'])->toBeFalse();
                $state = 'quarantined';
                throw new AgentRunnerFailure('instance.finalize-source-removal', AgentRunnerFailure::Transport, 'Connection closed.');
            }
            expect($input['force'])->toBeTrue();
            expect($input['independent_worktree'])->toBeTrue();

            return ['state' => 'finalized', 'receipt' => $input['receipt']];
        });
        $action = sourceRemovalAction($removal, $lock);
        $projector = Mockery::mock(InstanceRemovalProjector::class);
        $projector->shouldReceive('clearRouteTarget')->once()->andReturnUsing(static function (InstanceRemovalMember $record): string {
            Route::query()->findOrFail($record->route_id)->delete();

            return 'deleted';
        });
        $projector->shouldReceive('withdrawPhpPool')->andReturnNull();
        $projector->shouldReceive('cleanupRuntime')->once()->andReturnNull();
        app()->instance(InstanceRemovalProjector::class, $projector);
        $action = app(DestroyInstanceAction::class);

        expect(fn () => $action->execute($instance->refresh(), false, runTeardown: false))->toThrow(InstanceRemovalException::class);

        expect($member->refresh()->source_finalized_at)->toBeNull();
        expect($member->runtime_cleaned_at)->toBeNull();
        $completed = $action->execute($instance->refresh(), true, runTeardown: false);
        expect($completed->id)->toBe($member->instance_removal_id);
        expect($completed->force)->toBeTrue();
        expect($completed->status)->toBe(InstanceRemovalStatus::Completed);
        $this->assertModelMissing($instance);
        expect($attempt)->toBe(2);
    });

    it('refuses removal while the create lifecycle owns the environment lock', function (bool $failureRecord): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update([
            'status' => InstanceState::CheckoutPrepared, 'source_prepare_id' => 'attempt-1',
            'failed_step' => $failureRecord ? 'source_resolved' : null,
            'error_code' => $failureRecord ? 'instance.branch_resolution_failed' : null,
        ]);
        sourceRemovalAction($removal, $lock);
        $directory = sys_get_temp_dir().'/orbit-create-removal-lock-'.Str::uuid();
        $clock = 0.0;
        app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock(
            $directory, new CommandDeadline,
            clock: static function () use (&$clock): float {
                return $clock;
            },
            wait: static function () use (&$clock): void {
                $clock += 31.0;
            },
        ));
        $action = app(DestroyInstanceAction::class);
        try {
            new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline)->run([$instance->id], function () use ($action, $instance): void {
                expect(fn () => $action->execute($instance, true))
                    ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('env.operation_busy'));
            });
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }

        $this->assertModelExists($instance);
        $this->assertDatabaseCount('instance_removals', 0);
        expect($agents->calls)->toBe([]);
    })->with([false, true]);

    it('preserves normal content checks for an incomplete in-place registration', function (bool $force): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture(true);
        $instance->update([
            'status' => InstanceState::Reserved, 'failed_step' => 'registration', 'error_code' => 'instance.registration_incomplete',
            'registration_request_id' => 'registration-1', 'registration_original_path' => $instance->checkout_path,
            'registration_authoritative_path' => $instance->checkout_path, 'registration_relocation_state' => 'relocating',
            'registration_repository_identity' => 'example.test/site', 'registration_common_repository_path' => '/srv/orbit/apps/site/default/.git',
            'registration_source_digest' => str_repeat('a', 64), 'registration_detached' => false,
        ]);
        $dirty = true;
        $agents->answer('instance.inspect-source', static function (array $input) use (&$dirty, $force): array {
            expect($input['inspect_content'])->toBe(! $force);
            $row = sourceRemovalEvidence($input['checkout'], true);
            $row['dirty'] = base64_encode($dirty ? '1' : '0');

            return $row;
        })->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', static function (array $input) use ($force): array {
                expect($input['force'])->toBe($force);
                expect($input['unresolved'])->toBeFalse();
                expect($input['common_repository'])->toBe('/srv/orbit/apps/site/default');

                return ['state' => 'finalized', 'receipt' => $input['receipt']];
            });
        $action = sourceRemovalAction($removal, $lock);
        if (! $force) {
            expect(fn () => $action->execute($instance, false))->toThrow(ResourceOperationException::class);
            $this->assertModelExists($instance);
            $this->assertDatabaseCount('instance_removals', 0);
            $dirty = false;
        }

        $completed = $action->execute($instance, $force);

        expect($completed->status)->toBe(InstanceRemovalStatus::Completed);
        $this->assertModelMissing($instance);
    })->with([false, true]);

    it('refuses to cascade an interrupted creation into a registered worktree', function (): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update(['status' => InstanceState::SourceResolved, 'source_prepare_id' => 'attempt-1']);
        $sibling = Instance::query()->create([
            'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'sibling',
            'checkout_path' => '/srv/orbit/apps/site/sibling', 'source_layout' => 'worktree',
            'status' => InstanceState::SourceResolved, 'branch' => 'sibling', 'starting_commit' => str_repeat('a', 40),
        ]);
        $agents->answer('instance.inspect-source', static function (array $input) use ($sibling): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['worktrees'] = base64_encode(base64_decode($row['worktrees'])."worktree {$sibling->checkout_path}\0HEAD ".str_repeat('a', 40)."\0branch refs/heads/sibling\0\0");

            return $row;
        });

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, true))
            ->toThrow(ResourceOperationException::class, 'linked worktrees');

        $this->assertModelExists($instance);
        $this->assertModelExists($sibling);
        $this->assertDatabaseCount('instance_removals', 0);
        expect($agents->commands())->not->toContain('instance.prepare-source-removal');
    });

    it('protects unconfirmed and post-activation worktrees before removal acceptance', function (string $state, bool $legacy): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture(true);
        $instance->update([
            'status' => match ($state) {
                'active' => InstanceState::Active,
                'resolved' => InstanceState::SourceResolved,
                default => InstanceState::Reserved,
            },
            'starting_commit' => in_array($state, ['active', 'resolved'], true) ? str_repeat('a', 40) : null,
            'source_prepare_id' => $legacy ? null : 'attempt-1', 'task_workspace_routed' => false,
        ]);
        $agents->refuse('instance.inspect-source', match ($state) {
            'unconfirmed' => 'instance.ownership_mismatch',
            'foreign' => 'instance.layout_mismatch',
            default => 'instance.path_mismatch',
        });

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, true))->toThrow(ResourceOperationException::class);

        $this->assertModelExists($instance);
        $this->assertDatabaseCount('instance_removals', 0);
        expect($agents->commands())->not->toContain('instance.prepare-source-removal');
    })->with(['unconfirmed', 'foreign', 'symlink', 'resolved', 'active'])->with([false, true]);

    it('keeps an absent reservation when native finalization detects an appeared directory', function (): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture(true);
        $instance->update(['status' => InstanceState::Reserved, 'starting_commit' => null, 'branch' => null, 'source_prepare_id' => 'attempt-1', 'task_workspace_routed' => false]);
        $agents->answer('instance.inspect-source', static function (array $input): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['source_identity'] = base64_encode('absent');
            $row['commit'] = base64_encode(str_repeat('0', 40));
            $row['branch'] = '';

            return $row;
        })->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->refuse('instance.finalize-source-removal', 'instance.path_mismatch');

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, true))->toThrow(InstanceRemovalException::class);

        $this->assertModelExists($instance);
        expect(InstanceRemoval::query()->sole()->status)->toBe(InstanceRemovalStatus::Failed);
        expect(InstanceRemovalMember::query()->sole()->finalization_receipt)->toBeNull();
    });

    it('keeps normal teardown for a healthy unrouted workspace with a preparation receipt', function (): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update(['status' => InstanceState::SourceResolved, 'task_workspace_routed' => false, 'source_prepare_id' => 'attempt-1']);
        ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'normal-teardown', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
        $teardown = new LifecycleAgent;
        app()->instance(ProjectLifecycleRunner::class, $teardown->runner());
        $agents->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        $completed = sourceRemovalAction($removal, $lock)->execute($instance, true);

        expect($completed->status)->toBe(InstanceRemovalStatus::Completed);
        expect($teardown->inputs)->toHaveCount(1);
        expect($teardown->inputs[0]['command'])->toBe('cleanup');
        $this->assertModelMissing($instance);
    });

    it('refuses incomplete creation evidence before accepting a removal', function (?string $step, ?string $code): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update(['status' => InstanceState::CheckoutPrepared, 'branch' => null, 'starting_commit' => null, 'failed_step' => $step, 'error_code' => $code]);

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, true))->toThrow(ResourceOperationException::class);

        expect($agents->calls)->toBe([]);
        $this->assertModelExists($instance);
        $this->assertDatabaseCount('instance_removals', 0);
    })->with([[null, null], ['source_resolved', null], [null, 'instance.branch_resolution_failed']]);

    it('does not waive repository ownership for interrupted creation', function (bool $force): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update(['status' => InstanceState::SourceResolved, 'failed_step' => 'active', 'error_code' => 'instance.runtime_failed']);
        $agents->answer('instance.inspect-source', static function (array $input): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['origin'] = base64_encode('https://example.test/other.git');

            return $row;
        });

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, $force))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.source_origin_mismatch'));
        $this->assertModelExists($instance);
        $this->assertDatabaseCount('instance_removals', 0);
        expect($agents->commands())->not->toContain('instance.prepare-source-removal');
    })->with([false, true]);

    it('preserves a pre-existing reserved checkout without preparation ownership', function (bool $force): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update(['status' => InstanceState::Reserved, 'starting_commit' => null, 'branch' => null, 'failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
        $before = $instance->fresh()->getAttributes();

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, $force))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));

        expect($instance->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('instance_removals', 0);
        expect($agents->commands())->not->toContain('instance.prepare-source-removal');
    })->with([false, true]);

    it('requires complete matching in-place registration evidence', function (array $change): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $instance->update([
            'status' => InstanceState::Reserved, 'failed_step' => 'registration', 'error_code' => 'instance.registration_incomplete',
            'registration_request_id' => 'registration-1', 'registration_original_path' => $instance->checkout_path,
            'registration_authoritative_path' => $instance->checkout_path, 'registration_relocation_state' => 'reserved',
            'registration_repository_identity' => 'example.test/site', 'registration_common_repository_path' => $instance->checkout_path.'/.git',
            'registration_source_digest' => str_repeat('a', 64), 'registration_detached' => false,
            ...$change,
        ]);

        expect(fn () => sourceRemovalAction($removal, $lock)->execute($instance, true))->toThrow(ResourceOperationException::class);

        $this->assertModelExists($instance);
        $this->assertDatabaseCount('instance_removals', 0);
        expect($agents->commands())->not->toContain('instance.prepare-source-removal');
    })->with([
        'request' => [['registration_request_id' => null]],
        'digest' => [['registration_source_digest' => null]],
        'move' => [['registration_original_path' => '/external']],
        'authority' => [['registration_authoritative_path' => '/external']],
        'commit' => [['starting_commit' => str_repeat('c', 40)]],
        'repository' => [['registration_repository_identity' => 'example.test/other']],
        'common' => [['registration_common_repository_path' => '/other/.git']],
        'detached' => [['registration_detached' => true]],
        'clone' => [['failed_step' => 'checkout_prepared', 'registration_request_id' => null]],
    ]);

    it('recovers absent source without clearing its receipt checkpoint early', function (bool $worktree, bool $legacy, bool $retry): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture($worktree);
        $instance->update([
            'status' => InstanceState::Reserved, 'branch' => null, 'starting_commit' => null,
            'source_prepare_id' => $legacy ? null : 'attempt-absent', 'task_workspace_routed' => false,
            'failed_step' => $worktree ? null : 'checkout_prepared', 'error_code' => $worktree ? null : 'instance.clone_failed',
        ]);
        $agents->answer('instance.inspect-source', static function (array $input): array {
            expect($input['allow_absent'])->toBeTrue();
            expect($input['inspect_content'])->toBeFalse();
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['branch'] = '';
            $row['commit'] = base64_encode(str_repeat('0', 40));
            $row['source_identity'] = base64_encode('absent');

            return $row;
        })->answer('instance.read-source-removal-state', ['state' => 'present']);
        $attempts = 0;
        $agents->answer('instance.finalize-source-removal', static function (array $input) use ($retry, &$attempts): array {
            expect($input['source_identity'])->toBe('absent');
            expect($input['unresolved'])->toBeTrue();
            expect($input['force'])->toBeTrue();
            if (++$attempts === 1 && $retry) {
                throw new AgentRunnerFailure('instance.finalize-source-removal', AgentRunnerFailure::Transport, 'Connection closed.');
            }

            return ['state' => 'finalized', 'receipt' => $input['receipt']];
        });
        $action = sourceRemovalAction($removal, $lock);
        if ($retry) {
            expect(fn () => $action->execute($instance, false))->toThrow(InstanceRemovalException::class);
            $failed = InstanceRemoval::query()->sole();
            expect($failed->failed_step)->toBe(InstanceRemovalStep::SourceFinalization);
            expect($failed->members->sole()->source_prepared_at)->not->toBeNull();
            expect($failed->members->sole()->source_finalized_at)->toBeNull();
            expect($failed->members->sole()->finalization_receipt)->toBeNull();
            $this->assertModelExists($instance);
        }

        $completed = $action->execute($instance->refresh(), false);

        expect($completed->status)->toBe(InstanceRemovalStatus::Completed);
        expect($completed->members->sole()->source_identity)->toBe('absent');
        expect($completed->members->sole()->finalization_receipt)->toBeString();
        expect($attempts)->toBe($retry ? 2 : 1);
        if ($retry) {
            expect($completed->id)->toBe($failed->id);
        }
        $this->assertModelMissing($instance);
        expect($agents->commands())->not->toContain('instance.check-publication');
    })->with([
        'failed clone' => [false, false],
        'prepared worktree' => [true, false],
        'worktree without preparation ID' => [true, true],
    ])->with([false, true]);

    it('cleans partial source and routed intent without teardown or publication reads', function (string $state, bool $force): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $resolved = in_array($state, ['source_resolved', 'failed-runtime'], true);
        $failed = str_starts_with($state, 'failed-');
        $instance->update([
            'status' => match ($state) {
                'failed-runtime' => InstanceState::SourceResolved,
                'failed-checkout' => InstanceState::CheckoutPrepared,
                default => $state,
            },
            'starting_commit' => $resolved ? str_repeat('a', 40) : null, 'branch' => $resolved ? 'dev' : null,
            'source_prepare_id' => $failed ? null : 'attempt-1', 'vite_port' => 5200,
            'failed_step' => $failed ? ($resolved ? 'active' : 'source_resolved') : null,
            'error_code' => $failed ? ($resolved ? 'instance.runtime_failed' : 'instance.branch_resolution_failed') : null,
        ]);
        $sibling = Instance::query()->create([
            'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'sibling',
            'checkout_path' => '/srv/orbit/apps/site/sibling', 'status' => InstanceState::Reserved,
        ]);
        $route = Route::query()->create([
            'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
            'generation_basis_node_id' => $instance->node_id, 'domain' => 'crashed.site.test',
            'provenance' => RouteProvenance::Generated, 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        if ($failed) {
            $route->update(['status' => RouteStatus::Failed, 'failed_step' => 'source_resolved', 'error_code' => 'instance.branch_resolution_failed']);
        }
        DB::table('vite_port_assignments')->insert(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'port' => 5200]);
        ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'must-not-run', 'command' => 'exit 1', 'timeout_seconds' => 30, 'position' => 0]);
        $teardown = new LifecycleAgent;
        app()->instance(ProjectLifecycleRunner::class, $teardown->runner());
        $agents->answer('instance.inspect-source', static function (array $input) use ($resolved, $failed): array {
            expect($input['inspect_content'])->toBeFalse();
            expect($input['unresolved'])->toBe(! $resolved);
            expect($input['prepare_id'])->toBe($failed ? '' : 'attempt-1');
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['branch'] = base64_encode($resolved ? 'dev' : '');
            $row['commit'] = base64_encode(str_repeat($resolved ? 'a' : '0', 40));
            $row['dirty'] = base64_encode('1');

            return $row;
        })->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', static function (array $input) use ($resolved): array {
                expect($input['force'])->toBeTrue();
                expect($input['unresolved'])->toBe(! $resolved);
                expect($input['git_read']->isEmpty())->toBeTrue();

                return ['state' => 'finalized', 'receipt' => $input['receipt']];
            });
        $action = sourceRemovalAction($removal, $lock);

        $completed = $action->execute($instance, $force);

        expect($completed->status)->toBe(InstanceRemovalStatus::Completed);
        expect($completed->members)->toHaveCount(1);
        expect($completed->members->sole()->runtime_published)->toBeFalse();
        expect($teardown->inputs)->toBe([]);
        expect($agents->commands())->not->toContain('instance.check-publication');
        $inspections = array_values(array_filter($agents->calls, static fn (array $call): bool => $call['command'] === 'instance.inspect-source'));
        expect($inspections[0]['input']['allow_absent'])->toBeTrue();
        expect($inspections[array_key_last($inspections)]['input']['allow_absent'])->toBeFalse();
        $this->assertModelMissing($instance);
        $this->assertModelMissing($route);
        $this->assertModelExists($sibling);
        $this->assertDatabaseMissing('vite_port_assignments', ['instance_id' => $instance->id]);
    })->with(['reserved', 'checkout_prepared', 'source_resolved', 'failed-runtime', 'failed-checkout'])->with([false, true]);
});

describe('recorded source removal commands', function (): void {
    it('reports a cross-device refusal without recording completion and permits retry', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', ['state' => 'cross_device', 'receipt' => null]);

        expect(fn () => $removal->finalize($member))->toThrow(function (RuntimeConvergenceException $exception) use ($instance): void {
            expect($exception->errorCode)->toBe('instance.removal_incomplete');
            expect($exception->result?->exitCode)->toBe(1);
            expect($exception->result?->stdout)->toBe("{\"state\":\"cross_device\"}\n");
            expect($exception->result?->stderr)->toStartWith("cross-device: the checkout [{$instance->checkout_path}]");
        });
        expect($member->fresh()->source_finalized_at)->toBeNull();
        $agents->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);
        expect($removal->finalize($member))->toBeString();
    });

    it('refuses changed Project ownership or repository before any recorded command', function (bool $foreign): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        if ($foreign) {
            $project = Project::query()->create([
                'name' => 'Foreign', 'slug' => 'foreign', 'repository_url' => 'https://example.test/foreign.git', 'default_branch' => 'main',
            ]);
            $instance->update(['project_id' => $project->id]);
        } else {
            $instance->project->forceFill([
                'repository_url' => 'https://example.test/other.git', 'repository_identity' => 'example.test/other',
            ])->save();
        }
        $agents->calls = [];

        expect(fn () => $removal->revalidate($member))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.removal_conflict');
        });
        expect($agents->calls)->toBe([]);
    })->with([false, true]);

    it('uses the recorded checkout parent after a Project slug change', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $instance->project->update(['slug' => 'renamed']);
        $member = sourceRemovalRecord($removal, $instance);
        $agents->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        $removal->finalize($member);

        expect($agents->calls[array_key_last($agents->calls)]['input']['grouping_directory'])->toBe('/srv/orbit/apps/site');
    });

    it('passes the observed commit while retaining the historical starting commit', function (bool $force, bool $detached): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        if ($detached) {
            $instance->update(['branch' => null]);
        }
        $agents->answer('instance.inspect-source', static function (array $input) use ($detached): array {
            $row = sourceRemovalEvidence($input['checkout'], false);
            $row['commit'] = base64_encode(str_repeat('b', 40));
            $row['branch'] = base64_encode($detached ? '' : 'dev');
            $row['worktrees'] = base64_encode("worktree {$input['checkout']}\0HEAD ".str_repeat('b', 40).($detached ? "\0detached\0\0" : "\0branch refs/heads/dev\0\0"));

            return $row;
        });
        $member = sourceRemovalRecord($removal, $instance, $force);
        $agents->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        $removal->finalize($member);

        expect($member->starting_commit)->toBe(str_repeat('a', 40));
        expect($member->source_commit)->toBe(str_repeat('b', 40));
        expect($member->branch)->toBe($detached ? null : 'dev');
        expect($agents->calls[array_key_last($agents->calls)]['input'])->toMatchArray([
            'commit' => str_repeat('b', 40), 'branch' => $detached ? '' : 'dev', 'force' => $force,
        ]);
    })->with([false, true])->with([false, true]);

    it('rejects changed inspection evidence during recorded recovery', function (string $field, string $value): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->calls = [];
        $agents->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.inspect-source', static function (array $input) use ($field, $value): array {
                $row = sourceRemovalEvidence($input['checkout'], false);
                $row[$field] = base64_encode($value);

                return $row;
            });

        expect(fn () => $removal->revalidate($member))->toThrow(RuntimeConvergenceException::class);
        expect($agents->commands())->toBe(['instance.read-source-removal-state', 'instance.inspect-source']);
        expect($member->fresh()->source_finalized_at)->toBeNull();
    })->with([
        'replaced directory' => ['source_identity', '12:999'],
        'origin' => ['origin', 'https://example.test/other.git'],
        'control byte' => ['origin', "https://example.test/site.git\n"],
        'branch' => ['branch', 'changed'],
        'commit' => ['commit', str_repeat('c', 40)],
        'layout' => ['common', '/srv/orbit/apps/site/shared/.git'],
        'inventory' => ['worktrees', "worktree /srv/orbit/apps/site/dev\0HEAD ".str_repeat('a', 40)."\0branch refs/heads/dev\0\0worktree /srv/orbit/apps/site/late\0HEAD ".str_repeat('a', 40)."\0branch refs/heads/late\0\0"],
    ]);

    it('accepts canonical-equivalent origin evidence on a retry', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->answer('instance.read-source-removal-state', ['state' => 'present'])
            ->answer('instance.inspect-source', static function (array $input): array {
                $row = sourceRemovalEvidence($input['checkout'], false);
                $row['origin'] = base64_encode('ssh://git@EXAMPLE.TEST:22/site.git/');

                return $row;
            })->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        expect($removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Present);
        expect($removal->finalize($member))->toBeString();
        expect($agents->calls[array_key_last($agents->calls)]['input']['origin'])->toBe('ssh://git@EXAMPLE.TEST:22/site.git/');
    });

    it('prepares and finalizes the recorded identity under the per-Node lock', function (): void {
        [$removal, $agents, $instance, $lock] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->answer('instance.read-source-removal-state', static function (array $input, Node $node) use ($lock): array {
            expect($lock->held)->toBe($node->id);

            return ['state' => 'present'];
        });
        $agents->answer('instance.finalize-source-removal', function (array $input) use ($lock, $member): array {
            expect($lock->held)->toBe($member->node_id);
            expect($input)->toMatchArray([
                'checkout' => '/srv/orbit/apps/site/dev', 'root' => '/srv/orbit/apps',
                'common_repository' => '/srv/orbit/apps/site/dev', 'layout' => 'checkout', 'branch' => 'dev',
                'operation' => $member->instance_removal_id, 'member' => (string) $member->id,
                'digest' => $member->source_digest, 'source_identity' => '12:34', 'commit' => str_repeat('a', 40),
                'force' => true, 'unresolved' => false, 'independent_worktree' => false,
                'grouping_directory' => '/srv/orbit/apps/site', 'user' => 'orbit', 'group' => 'orbit',
            ]);

            return ['state' => 'finalized', 'receipt' => $input['receipt']];
        });

        expect($removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Present);
        $removal->inspectRecorded($member, InstanceSourceRevalidationState::Present);
        $receipt = $removal->finalize($member);

        expect($receipt)->toBe(hash('sha256', "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized"));
        expect($agents->commands())->toBe([
            'instance.inspect-source', 'instance.inspect-source', 'instance.prepare-source-removal',
            'instance.read-source-removal-state', 'instance.inspect-source', 'instance.inspect-source',
            'instance.read-source-removal-state', 'instance.inspect-source', 'instance.finalize-source-removal',
        ]);
        expect($lock->held)->toBeNull();
        expect($member->fresh()->source_finalized_at)->toBeNull();
    });

    it('returns the durable completion receipt without repeating finalization', function (): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->calls = [];
        $agents->answer('instance.read-source-removal-state', ['state' => 'completed']);

        expect($removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Completed);
        $first = $removal->finalize($member);
        expect($removal->finalize($member))->toBe($first);
        expect($agents->commands())->toBe(array_fill(0, 3, 'instance.read-source-removal-state'));
    });

    it('maps quarantine inspection to the recorded logical source before finalization', function (string $state): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->calls = [];
        $agents->answer('instance.read-source-removal-state', ['state' => $state]);
        $agents->answer('instance.read-removal-receipt', ['state' => 'intact']);
        $agents->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        expect($removal->revalidate($member))->toBe($state === 'quarantined'
            ? InstanceSourceRevalidationState::Quarantined : InstanceSourceRevalidationState::ReceiptPendingCleanup);
        $removal->finalize($member);

        $inspections = array_values(array_filter($agents->calls, static fn (array $call): bool => $call['command'] === 'instance.inspect-source'));
        expect($inspections[0]['input']['checkout'])->toBe('/srv/orbit/apps/.orbit-removals/'.$member->instance_removal_id.'.'.$member->id.'.quarantine');
        expect($agents->calls[array_key_last($agents->calls)]['input']['checkout'])->toBe($instance->checkout_path);
        expect($agents->commands())->not->toContain('instance.clean-removal-receipt');
    })->with(['before receipt' => 'quarantined', 'intact receipt' => 'receipt_pending_cleanup']);

    it('finishes an incomplete receipt without trying to inspect partially deleted Git data', function (bool $worktree, string $reported): void {
        [$removal, $agents, $instance] = sourceRemovalFixture($worktree);
        $member = sourceRemovalRecord($removal, $instance);
        $agents->calls = [];
        $agents->answer('instance.read-source-removal-state', ['state' => $reported])
            ->answer('instance.read-removal-receipt', ['state' => 'incomplete'])
            ->answer('instance.clean-removal-receipt', static fn (array $input): array => ['receipt' => $input['receipt']]);

        expect($removal->revalidate($member))->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup);
        $receipt = $removal->finalize($member);

        expect($receipt)->toBeString();
        expect($agents->commands())->not->toContain('instance.inspect-source', 'instance.finalize-source-removal');
        expect($agents->calls[array_key_last($agents->calls)]['input'])->toMatchArray([
            'checkout' => $instance->checkout_path, 'source_identity' => '12:34',
            'grouping_directory' => '/srv/orbit/apps/site', 'receipt' => $receipt,
        ]);
    })->with([
        'checkout partially removed' => [false, 'receipt_pending_cleanup'],
        'worktree partially removed' => [true, 'receipt_pending_cleanup'],
        'worktree gone with administration remaining' => [true, 'completed'],
    ]);

    it('refuses recovery when the agent cannot authenticate the source or receipt', function (string $command): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $before = $member->getAttributes();
        $agents->answer('instance.read-source-removal-state', ['state' => 'receipt_pending_cleanup']);
        $agents->refuse($command, 'instance.removal_refused');
        $agents->calls = [];

        expect(fn () => $removal->revalidate($member))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.removal_conflict');
        });
        expect($member->fresh()->getAttributes())->toBe($before);
        expect($agents->commands())->not->toContain('instance.clean-removal-receipt', 'instance.finalize-source-removal');
    })->with(['instance.read-source-removal-state', 'instance.read-removal-receipt']);

    it('maps a finalization refusal and rejects an incorrect receipt', function (bool $refused): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $agents->answer('instance.read-source-removal-state', ['state' => 'present']);
        if ($refused) {
            $agents->refuse('instance.finalize-source-removal', 'instance.removal_refused');
        } else {
            $agents->answer('instance.finalize-source-removal', ['state' => 'finalized', 'receipt' => 'wrong']);
        }

        expect(fn () => $removal->finalize($member))->toThrow(function (RuntimeConvergenceException $exception) use ($refused): void {
            expect($exception->errorCode)->toBe($refused ? 'instance.removal_incomplete' : 'instance.finalization_evidence_invalid');
        });
        expect($member->fresh()->source_finalized_at)->toBeNull();
    })->with([true, false]);

    it('refuses recorded ownership drift before any command', function (array $change): void {
        [$removal, $agents, $instance] = sourceRemovalFixture();
        $member = sourceRemovalRecord($removal, $instance);
        $instance->update($change);
        $agents->calls = [];

        expect(fn () => $removal->finalize($member))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.removal_conflict');
        });
        expect($agents->calls)->toBe([]);
    })->with([
        'layout' => [['source_layout' => 'worktree']],
        'placement' => [['checkout_path' => '/srv/orbit/apps/site/replaced']],
        'branch' => [['branch' => 'changed']],
        'starting commit' => [['starting_commit' => str_repeat('c', 40)]],
    ]);

    it('allows independent sibling changes only for a forced worktree removal', function (bool $force): void {
        [$removal, $agents, $instance] = sourceRemovalFixture(true);
        $member = sourceRemovalRecord($removal, $instance, $force);
        $agents->answer('instance.read-source-removal-state', ['state' => 'present']);
        $agents->answer('instance.inspect-source', static function (array $input): array {
            $row = sourceRemovalEvidence($input['checkout'], true);
            $row['worktrees'] = base64_encode(base64_decode($row['worktrees'])."worktree /srv/orbit/apps/site/new-sibling\0HEAD ".str_repeat('a', 40)."\0branch refs/heads/new-sibling\0\0");

            return $row;
        });
        $agents->answer('instance.finalize-source-removal', static fn (array $input): array => ['state' => 'finalized', 'receipt' => $input['receipt']]);

        if ($force) {
            $removal->finalize($member);
            expect($agents->calls[array_key_last($agents->calls)]['input']['independent_worktree'])->toBeTrue();
        } else {
            expect(fn () => $removal->finalize($member))->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.removal_conflict');
            });
            expect($agents->commands())->not->toContain('instance.finalize-source-removal');
        }
    })->with([true, false]);
});

/** @return array{RemoteDevelopmentInstanceSourceRemoval, FakeAgentCommandRunner, Instance, RemovalCommandLock} */
function sourceRemovalFixture(bool $worktree = false): array
{
    $node = Node::query()->create([
        'name' => 'app-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10', 'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, production: false);
    $project = Project::query()->create([
        'name' => 'Site', 'slug' => 'site', 'repository_url' => 'https://example.test/site.git', 'default_branch' => 'main', 'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'node_id' => $node->id, 'project_id' => $project->id, 'name' => 'dev',
        'checkout_path' => '/srv/orbit/apps/site/dev', 'source_layout' => $worktree ? 'worktree' : 'checkout',
        'branch' => 'dev', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::Active,
    ]);
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $lock = new RemovalCommandLock;
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.inspect-source', static function (array $input, Node $node) use ($lock, $worktree): array {
        expect($lock->held)->toBe($node->id);

        return sourceRemovalEvidence($input['checkout'], $worktree);
    })->answer('instance.prepare-source-removal', static function (array $input, Node $node) use ($lock): array {
        expect($lock->held)->toBe($node->id);

        return [];
    })->answer('instance.check-publication', ['published' => true]);
    $removal = new RemoteDevelopmentInstanceSourceRemoval(
        $agents, $accounts, new CheckoutRemovalBoundary(new ProtectedPathCatalog), $lock, app(RepositoryReadAccess::class),
    );

    return [$removal, $agents, $instance, $lock];
}

/** @return array<string, string> */
function sourceRemovalEvidence(string $physical, bool $worktree): array
{
    $commit = str_repeat('a', 40);
    $inventory = "worktree {$physical}\0HEAD {$commit}\0branch refs/heads/dev\0\0";
    if ($worktree) {
        $inventory .= "worktree /srv/orbit/apps/site/default\0HEAD {$commit}\0branch refs/heads/main\0\0";
    }

    return array_map(base64_encode(...), [
        'top' => $physical, 'common' => $worktree ? '/srv/orbit/apps/site/default/.git' : $physical.'/.git',
        'origin' => 'https://example.test/site.git', 'branch' => 'dev', 'commit' => $commit,
        'dirty' => '0', 'source_identity' => '12:34', 'worktrees' => $inventory,
    ]);
}

function sourceRemovalRecord(RemoteDevelopmentInstanceSourceRemoval $removal, Instance $instance, bool $force = true): InstanceRemovalMember
{
    $inventory = $removal->inspect($instance, $force);
    $route = Route::query()->create([
        'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id, 'domain' => 'source-finalization.test',
        'provenance' => RouteProvenance::Generated, 'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $operation = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(), 'requested_instance_id' => $instance->id, 'requested_name' => $instance->name,
        'force' => $force, 'inventory_digest' => $inventory->digest, 'total' => 1,
        'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
    $member = $operation->members()->create([
        'position' => 0, 'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'route_id' => $route->id,
        'name' => $instance->name, 'environment' => 'development', 'runtime_published' => true,
        'source_layout' => $inventory->layout, 'repository_identity' => $inventory->repositoryIdentity,
        'checkout_path' => $inventory->checkoutPath, 'root' => $instance->effectiveRoot(), 'branch' => $inventory->branch,
        'starting_commit' => $instance->starting_commit, 'source_commit' => $inventory->startingCommit,
        'common_repository_path' => $inventory->commonRepositoryPath, 'source_identity' => $inventory->sourceIdentity,
        'linked_worktree_paths' => $inventory->linkedWorktreePaths, 'source_digest' => $inventory->digest,
    ]);
    $instance->update(['status' => InstanceState::Removing]);
    $removal->prepare($member);
    $member->update(['source_prepared_at' => now()]);

    return $member->refresh();
}

final class RemovalCommandLock implements AppDevSourceOperationLock
{
    public ?int $held = null;

    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        $this->held = $nodeId;
        try {
            return $operation();
        } finally {
            $this->held = null;
        }
    }
}

function sourceRemovalAction(RemoteDevelopmentInstanceSourceRemoval $source, AppDevSourceOperationLock $lock): DestroyInstanceAction
{
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $source);
    app()->instance(AppDevSourceOperationLock::class, $lock);
    $projector = Mockery::mock(InstanceRemovalProjector::class);
    $projector->shouldReceive('clearRouteTarget')->andReturnUsing(static function (InstanceRemovalMember $member): string {
        Route::query()->findOrFail($member->route_id)->delete();

        return 'deleted';
    });
    $projector->shouldReceive('withdrawPhpPool')->andReturnNull();
    $projector->shouldReceive('cleanupRuntime')->andReturnNull();
    app()->instance(InstanceRemovalProjector::class, $projector);

    return app(DestroyInstanceAction::class);
}
