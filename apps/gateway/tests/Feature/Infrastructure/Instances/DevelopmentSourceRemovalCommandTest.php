<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

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
