<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Instances\RemoteRegistrationSourceManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

describe('registration source facts', function (): void {
    it('sends the managed account and maps agent rows to repository facts', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $row = registrationRow();
        $agents->answer('instance.inspect-registration-source', ['rows' => [$row]]);

        $facts = $manager->inspect($instance->node, '/incoming/site', true);

        expect($facts)->toEqual([registrationFacts()]);
        expect($agents->calls)->toBe([[
            'node' => $instance->node->name,
            'command' => 'instance.inspect-registration-source',
            'input' => ['path' => '/incoming/site', 'include_worktrees' => true, 'user' => 'orbit', 'group' => 'orbit'],
        ]]);
    });

    it('refuses invalid or mixed repository evidence from inspection', function (array $rows): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->answerMalformed('instance.inspect-registration-source', ['rows' => $rows]);

        expect(fn () => $manager->inspect($instance->node, '/incoming/site', false))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            });
    })->with([
        'no sources' => [[]],
        'invalid row' => [[null]],
        'invalid layout' => [[registrationRow(['layout' => 'bare'])]],
        'invalid commit' => [[registrationRow(['commit' => 'invalid'])]],
        'invalid digest' => [[registrationRow(['source_digest' => 'invalid'])]],
        'detached branch mismatch' => [[registrationRow(['detached' => true])]],
        'invalid worktree paths' => [[registrationRow(['worktree_paths' => [1]])]],
        'mixed repositories' => [[registrationRow(), registrationRow(['repository_url' => 'https://example.test/other.git'])]],
    ]);

    it('rejects a resolved account that differs from the Node before inspecting it', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $instance->node->user = 'another';

        expect(fn () => $manager->inspect($instance->node, '/incoming/site', false))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_owner_invalid');
            });
        expect($agents->calls)->toBe([]);
    });

    it('maps inspection refusals to the registration source error', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->refuse('instance.inspect-registration-source', 'instance.registration_invalid');

        expect(fn () => $manager->inspect($instance->node, '/incoming/site', false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            });
    });
});

describe('retained registration evidence', function (): void {
    it('permits normal edits after the destination becomes authoritative', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationRow([
            'path' => $instance->checkout_path,
            'branch' => 'work', 'commit' => str_repeat('b', 40), 'source_digest' => str_repeat('c', 64),
        ])]]);
        $instance->update([
            'registration_relocation_state' => 'relocated',
            'registration_authoritative_path' => $instance->checkout_path,
        ]);

        $manager->validateRetained($instance->node, registrationFacts(), $instance->checkout_path);
        $manager->relocate($instance, registrationFacts());

        expect($agents->commands())->toBe(['instance.inspect-registration-source']);
    });

    it('refuses changed retained path layout or repository identity', function (array $change): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationRow($change)]]);

        expect(fn () => $manager->validateRetained($instance->node, registrationFacts(), '/incoming/site'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_conflict');
            });
    })->with([
        'path' => [['path' => '/other/site']],
        'layout' => [['layout' => 'worktree']],
        'repository' => [['repository_url' => 'https://example.test/replacement.git']],
    ]);

    it('requires the preserved branch commit and digest during relocation recovery', function (array $change): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationRow($change)]]);

        expect(fn () => $manager->validateRelocationRecovery($instance->node, registrationFacts(), '/incoming/site'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_conflict');
            });
    })->with([
        'branch' => [['branch' => 'other']],
        'detached' => [['branch' => null, 'detached' => true]],
        'commit' => [['commit' => str_repeat('b', 40)]],
        'content' => [['source_digest' => str_repeat('c', 64)]],
    ]);

    it('accepts unchanged recovery evidence and maps failed inspection to a conflict', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationRow()]]);
        $manager->validateRelocationRecovery($instance->node, registrationFacts(), '/incoming/site');
        $agents->refuse('instance.inspect-registration-source', 'instance.git_failed');

        foreach (['validateRetained', 'validateRelocationRecovery'] as $method) {
            expect(fn () => $manager->$method($instance->node, registrationFacts(), '/incoming/site'))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)->toBe('instance.registration_conflict');
                });
        }
    });
});

describe('registration relocation checkpoints', function (): void {
    it('records intent before prepare and original identity before cleanup without saving unrelated dirty fields', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $instance->update(['name' => 'main', 'checkout_path' => '/incoming/site']);
        $instance->name = 'default';
        $instance->checkout_path = '/srv/orbit/apps/site/default';
        $agents->answer('instance.relocate-registration-source', function (array $input) use ($instance): array {
            $stored = $instance->fresh();
            expect($stored->name)->toBe('main')->and($stored->checkout_path)->toBe('/incoming/site');
            if ($input['operation'] === 'prepare') {
                expect($stored->registration_relocation_state)->toBe('relocating')
                    ->and($stored->registration_authoritative_path)->toBe('/incoming/site')
                    ->and($stored->registration_source_device)->toBeNull()
                    ->and($stored->registration_source_inode)->toBeNull();
            } else {
                expect($input['operation'])->toBe('cleanup');
                expect($stored->registration_relocation_state)->toBe('original_cleanup')
                    ->and($stored->registration_authoritative_path)->toBe('/srv/orbit/apps/site/default')
                    ->and($stored->registration_source_device)->toBe(12)
                    ->and($stored->registration_source_inode)->toBe(34);
            }

            return ['identities' => [['id' => $instance->id, 'source_device' => 12, 'source_inode' => 34]]];
        });

        $manager->relocate($instance, registrationFacts());

        $member = [
            'id' => $instance->id, 'source' => '/incoming/site', 'destination' => '/srv/orbit/apps/site/default',
            'application' => '/srv/orbit/apps/site/default', 'layout' => 'checkout', 'commit' => str_repeat('a', 40),
            'branch' => 'main', 'detached' => false, 'digest' => str_repeat('b', 64),
            'common' => '/incoming/site/.git', 'worktrees' => ['/incoming/site'],
            'source_device' => null, 'source_inode' => null,
        ];
        expect(array_column($agents->calls, 'input'))->toBe([
            ['operation' => 'prepare', 'members' => [$member]],
            ['operation' => 'cleanup', 'members' => [[...$member, 'source_device' => 12, 'source_inode' => 34]]],
        ]);
        expect($instance->refresh()->only(['name', 'checkout_path', 'registration_relocation_state', 'registration_authoritative_path']))->toBe([
            'name' => 'main', 'checkout_path' => '/incoming/site', 'registration_relocation_state' => 'relocated',
            'registration_authoritative_path' => '/srv/orbit/apps/site/default',
        ]);
    });

    it('repeats only the unfinished phase after a lost response', function (string $lost, string $state, array $operations): void {
        [$manager, $agents, $instance] = registrationFixture();
        $interrupted = false;
        $agents->answer('instance.relocate-registration-source', function (array $input) use ($instance, $lost, &$interrupted): array {
            if (! $interrupted && $input['operation'] === $lost) {
                $interrupted = true;
                throw new AgentRunnerFailure('instance.relocate-registration-source', AgentRunnerFailure::MissingResult, 'Connection lost.');
            }

            return ['identities' => [['id' => $instance->id, 'source_device' => 12, 'source_inode' => 34]]];
        });
        expect(fn () => $manager->relocate($instance, registrationFacts()))->toThrow(RuntimeConvergenceException::class);
        expect($instance->refresh()->registration_relocation_state)->toBe($state);

        $manager->relocate($instance, registrationFacts());

        expect(array_column(array_column($agents->calls, 'input'), 'operation'))->toBe($operations);
        expect($instance->refresh()->registration_relocation_state)->toBe('relocated');
    })->with([
        'prepare response lost' => ['prepare', 'relocating', ['prepare', 'prepare', 'cleanup']],
        'cleanup response lost' => ['cleanup', 'original_cleanup', ['prepare', 'cleanup', 'cleanup']],
    ]);

    it('keeps cleanup evidence when the agent refuses a replaced original', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $instance->update([
            'registration_relocation_state' => 'destination_verified',
            'registration_authoritative_path' => $instance->checkout_path,
            'registration_source_device' => 12, 'registration_source_inode' => 34,
        ]);
        $agents->refuse('instance.relocate-registration-source', 'instance.registration_invalid');

        expect(fn () => $manager->relocate($instance, registrationFacts()))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });
        expect($instance->refresh()->registration_relocation_state)->toBe('original_cleanup')
            ->and($instance->registration_source_device)->toBe(12)
            ->and($instance->registration_source_inode)->toBe(34);
        expect(array_column(array_column($agents->calls, 'input'), 'operation'))->toBe(['cleanup']);
    });

    it('refuses incomplete preparation identities before asking for cleanup', function (array $identities): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->answerMalformed('instance.relocate-registration-source', ['identities' => $identities]);

        expect(fn () => $manager->relocate($instance, registrationFacts()))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });
        expect($instance->refresh()->registration_relocation_state)->toBe('relocating');
        expect(array_column(array_column($agents->calls, 'input'), 'operation'))->toBe(['prepare']);
    })->with([
        'missing identity' => [[]],
        'different set' => [[['id' => -1, 'source_device' => 12, 'source_inode' => 34]]],
        'partial identity' => [[['id' => 1, 'source_device' => 12, 'source_inode' => null]]],
    ]);

    it('retries the complete included set after a partial prepare loses its response', function (): void {
        [$manager, $agents, $first] = registrationFixture();
        $second = $first->replicate();
        $second->fill([
            'name' => 'other', 'checkout_path' => '/srv/orbit/apps/site/other', 'source_layout' => 'worktree',
            'registration_original_path' => '/incoming/other', 'registration_authoritative_path' => '/incoming/other',
            'registration_primary' => false,
        ])->save();
        $facts = new RegistrationSourceFacts(
            path: '/incoming/other', layout: InstanceSourceLayout::Worktree,
            repositoryUrl: 'https://example.test/site.git', repositoryIdentity: 'example.test/site',
            branch: 'other', detached: false, commit: str_repeat('a', 40), defaultBranch: 'main',
            inferredSlug: 'site', inferredRoot: null, commonRepositoryPath: '/incoming/site/.git',
            worktreePaths: ['/incoming/site', '/incoming/other'], sourceDigest: str_repeat('c', 64),
        );
        $interrupted = false;
        $agents->answer('instance.relocate-registration-source', function (array $input) use ($first, $second, &$interrupted): array {
            expect(array_column($input['members'], 'id'))->toBe([$first->id, $second->id]);
            $state = $input['operation'] === 'prepare' ? 'relocating' : 'original_cleanup';
            expect($first->fresh()->registration_relocation_state)->toBe($state)
                ->and($second->fresh()->registration_relocation_state)->toBe($state);
            if (! $interrupted) {
                $interrupted = true;
                throw new AgentRunnerFailure('instance.relocate-registration-source', AgentRunnerFailure::MissingResult, 'Connection lost after one source moved.');
            }
            if ($input['operation'] === 'cleanup') {
                expect(array_column($input['members'], 'source_inode'))->toBe([34, 56]);
            }

            return ['identities' => [
                ['id' => $second->id, 'source_device' => 12, 'source_inode' => 56],
                ['id' => $first->id, 'source_device' => 12, 'source_inode' => 34],
            ]];
        });
        $members = [['instance' => $first, 'facts' => registrationFacts()], ['instance' => $second, 'facts' => $facts]];
        expect(fn () => $manager->relocateSet($members))->toThrow(RuntimeConvergenceException::class);
        $first->refresh();
        $second->refresh();

        $manager->relocateSet($members);

        expect(array_column(array_column($agents->calls, 'input'), 'operation'))->toBe(['prepare', 'prepare', 'cleanup']);
        expect($first->refresh()->registration_relocation_state)->toBe('relocated')
            ->and($second->refresh()->registration_relocation_state)->toBe('relocated');
    });

    it('requires complete set checkpoints and one Node before a mutation', function (string $change): void {
        [$manager, $agents, $first] = registrationFixture();
        $second = $first->replicate();
        $second->name = 'other';
        $second->checkout_path = '/srv/orbit/apps/site/other';
        $second->registration_original_path = '/incoming/other';
        $second->registration_authoritative_path = '/incoming/other';
        $second->registration_primary = false;
        $second->save();
        if ($change === 'node') {
            $second->node_id = $first->node_id + 100;
        } elseif ($change === 'relocated') {
            $second->registration_relocation_state = 'relocated';
            $second->registration_authoritative_path = $second->checkout_path;
        } else {
            $second->registration_relocation_state = 'destination_verified';
            $second->registration_authoritative_path = $second->checkout_path;
            $second->registration_source_device = 12;
            $second->registration_source_inode = 34;
        }

        expect(fn () => $manager->relocateSet([
            ['instance' => $first, 'facts' => registrationFacts()],
            ['instance' => $second, 'facts' => registrationFacts()],
        ]))->toThrow(function (ResourceOperationException $exception) use ($change): void {
            expect($exception->errorCode)->toBe($change === 'node' ? 'instance.source_conflict' : 'instance.registration_evidence_invalid');
        });
        expect($agents->calls)->toBe([]);
        expect($first->refresh()->registration_relocation_state)->toBe('reserved');
    })->with(['node', 'relocated', 'cleanup']);
});

describe('in-place registration', function (): void {
    it('adopts and finalizes without moving sources and resumes only the unfinished phase', function (?string $lost, array $operations): void {
        [$manager, $agents, $instance] = registrationFixture();
        $instance->update(['checkout_path' => '/incoming/site']);
        $interrupted = false;
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationRow([
            'source_digest' => str_repeat('c', 64),
        ])]]);
        $agents->answer('instance.relocate-registration-source', function (array $input) use ($instance, $lost, &$interrupted): array {
            expect($instance->fresh()->registration_relocation_state)
                ->toBe($input['operation'] === 'adopt' ? 'relocating' : 'destination_verified');
            if (! $interrupted && $input['operation'] === $lost) {
                $interrupted = true;
                throw new AgentRunnerFailure('instance.relocate-registration-source', AgentRunnerFailure::MissingResult, 'Connection lost.');
            }

            return ['identities' => []];
        });
        if ($lost !== null) {
            expect(fn () => $manager->relocate($instance, registrationFacts()))->toThrow(RuntimeConvergenceException::class);
            $instance->refresh();
        }

        $manager->relocate($instance, registrationFacts());

        $mutations = array_filter($agents->calls, static fn (array $call): bool => $call['command'] === 'instance.relocate-registration-source');
        expect(array_column(array_column($mutations, 'input'), 'operation'))->toBe($operations);
        expect($instance->refresh()->registration_relocation_state)->toBe('relocated')
            ->and($instance->registration_source_device)->toBeNull()
            ->and($instance->registration_source_inode)->toBeNull();
    })->with([
        'successful adoption' => [null, ['adopt', 'adopt-finalize']],
        'lost adoption response' => ['adopt', ['adopt', 'adopt', 'adopt-finalize']],
        'lost finalization response with changed digest' => ['adopt-finalize', ['adopt', 'adopt-finalize', 'adopt-finalize']],
    ]);
});

describe('Laravel rollback receipts', function (): void {
    it('addresses the application directory for each receipt step', function (string $root, string $application): void {
        [$manager, $agents, $instance] = registrationFixture();
        $instance->root = $root;
        $agents->answer('instance.laravel-registration-receipt', []);

        $manager->prepareLaravelRollback($instance);
        $manager->restoreLaravelConfiguration($instance);
        $manager->discardLaravelRollback($instance);

        expect(array_column($agents->calls, 'input'))->toBe(array_map(
            static fn (string $operation): array => [
                'checkout' => '/srv/orbit/apps/site/default', 'instance_id' => $instance->id,
                'operation' => $operation, 'application' => $application,
            ], ['prepare', 'restore', 'discard'],
        ));
    })->with([
        'root Laravel' => ['public', '/srv/orbit/apps/site/default'],
        'nested Laravel' => ['server/web/public', '/srv/orbit/apps/site/default/server/web'],
    ]);

    it('maps a refused rollback receipt to the Laravel error', function (): void {
        [$manager, $agents, $instance] = registrationFixture();
        $agents->refuse('instance.laravel-registration-receipt', 'instance.registration_invalid');

        expect(fn () => $manager->prepareLaravelRollback($instance))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.laravel_rollback_failed');
            });
    });
});

/** @return array{RemoteRegistrationSourceManager, FakeAgentCommandRunner, Instance} */
function registrationFixture(): array
{
    $node = Node::query()->create([
        'name' => 'registration-'.Str::uuid(), 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.105', 'tld' => 'test',
    ]);
    $project = Project::query()->create([
        'name' => 'Site', 'slug' => 'site-'.Str::uuid(), 'repository_url' => 'https://example.test/site.git',
        'default_branch' => 'main',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'source_layout' => 'checkout', 'checkout_path' => '/srv/orbit/apps/site/default', 'branch' => 'main',
        'registration_original_path' => '/incoming/site', 'registration_request_id' => (string) Str::uuid(),
        'registration_primary' => true, 'registration_repository_url' => 'https://example.test/site.git',
        'registration_repository_identity' => 'example.test/site', 'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => '/incoming/site', 'status' => 'reserved',
    ]);
    $agents = new FakeAgentCommandRunner;
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };

    return [new RemoteRegistrationSourceManager($agents, $accounts), $agents, $instance];
}

/** @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function registrationRow(array $changes = []): array
{
    return array_replace([
        'path' => '/incoming/site', 'layout' => 'checkout', 'repository_url' => 'https://example.test/site.git',
        'branch' => 'main', 'detached' => false, 'commit' => str_repeat('a', 40), 'default_branch' => 'main',
        'inferred_slug' => 'site', 'inferred_root' => null, 'common_repository_path' => '/incoming/site/.git',
        'worktree_paths' => ['/incoming/site'], 'source_digest' => str_repeat('b', 64),
    ], $changes);
}

function registrationFacts(): RegistrationSourceFacts
{
    return new RegistrationSourceFacts(
        path: '/incoming/site', layout: InstanceSourceLayout::Checkout,
        repositoryUrl: 'https://example.test/site.git', repositoryIdentity: 'example.test/site',
        branch: 'main', detached: false, commit: str_repeat('a', 40), defaultBranch: 'main',
        inferredSlug: 'site', inferredRoot: null, commonRepositoryPath: '/incoming/site/.git',
        worktreePaths: ['/incoming/site'], sourceDigest: str_repeat('b', 64),
    );
}
