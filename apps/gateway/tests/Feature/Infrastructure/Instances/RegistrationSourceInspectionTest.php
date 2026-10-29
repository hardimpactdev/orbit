<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteRegistrationSourceManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

describe('registration source facts', function (): void {
    it('sends the managed account and maps agent rows to repository facts', function (): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $row = registrationInspectionRow();
        $agents->answer('instance.inspect-registration-source', ['rows' => [$row]]);

        $facts = $manager->inspect($instance->node, '/incoming/site', true);

        expect($facts)->toEqual([registrationInspectionFacts()]);
        expect($agents->calls)->toBe([[
            'node' => $instance->node->name,
            'command' => 'instance.inspect-registration-source',
            'input' => ['path' => '/incoming/site', 'include_worktrees' => true, 'user' => 'orbit', 'group' => 'orbit'],
        ]]);
    });

    it('refuses invalid or mixed repository evidence from inspection', function (array $rows): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $agents->answerMalformed('instance.inspect-registration-source', ['rows' => $rows]);

        expect(fn () => $manager->inspect($instance->node, '/incoming/site', false))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            });
    })->with([
        'no sources' => [[]],
        'invalid row' => [[null]],
        'invalid layout' => [[registrationInspectionRow(['layout' => 'bare'])]],
        'invalid commit' => [[registrationInspectionRow(['commit' => 'invalid'])]],
        'invalid digest' => [[registrationInspectionRow(['source_digest' => 'invalid'])]],
        'detached branch mismatch' => [[registrationInspectionRow(['detached' => true])]],
        'invalid worktree paths' => [[registrationInspectionRow(['worktree_paths' => [1]])]],
        'mixed repositories' => [[registrationInspectionRow(), registrationInspectionRow(['repository_url' => 'https://example.test/other.git'])]],
    ]);

    it('rejects a resolved account that differs from the Node before inspecting it', function (): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $instance->node->user = 'another';

        expect(fn () => $manager->inspect($instance->node, '/incoming/site', false))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_owner_invalid');
            });
        expect($agents->calls)->toBe([]);
    });

    it('maps inspection refusals to the registration source error', function (): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $agents->refuse('instance.inspect-registration-source', 'instance.registration_invalid');

        expect(fn () => $manager->inspect($instance->node, '/incoming/site', false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            });
    });
});

describe('retained registration evidence', function (): void {
    it('permits normal edits after the destination becomes authoritative', function (): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationInspectionRow([
            'path' => $instance->checkout_path,
            'branch' => 'work', 'commit' => str_repeat('b', 40), 'source_digest' => str_repeat('c', 64),
        ])]]);
        $instance->update([
            'registration_relocation_state' => 'relocated',
            'registration_authoritative_path' => $instance->checkout_path,
        ]);

        $manager->validateRetained($instance->node, registrationInspectionFacts(), $instance->checkout_path);

        expect($agents->commands())->toBe(['instance.inspect-registration-source']);
    });

    it('refuses changed retained path layout or repository identity', function (array $change): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationInspectionRow($change)]]);

        expect(fn () => $manager->validateRetained($instance->node, registrationInspectionFacts(), '/incoming/site'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_conflict');
            });
    })->with([
        'path' => [['path' => '/other/site']],
        'layout' => [['layout' => 'worktree']],
        'repository' => [['repository_url' => 'https://example.test/replacement.git']],
    ]);

    it('requires the preserved branch commit and digest during relocation recovery', function (array $change): void {
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationInspectionRow($change)]]);

        expect(fn () => $manager->validateRelocationRecovery($instance->node, registrationInspectionFacts(), '/incoming/site'))
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
        [$manager, $agents, $instance] = registrationInspectionFixture();
        $agents->answer('instance.inspect-registration-source', ['rows' => [registrationInspectionRow()]]);
        $manager->validateRelocationRecovery($instance->node, registrationInspectionFacts(), '/incoming/site');
        $agents->refuse('instance.inspect-registration-source', 'instance.git_failed');

        foreach (['validateRetained', 'validateRelocationRecovery'] as $method) {
            expect(fn () => $manager->$method($instance->node, registrationInspectionFacts(), '/incoming/site'))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)->toBe('instance.registration_conflict');
                });
        }
    });
});

/** @return array{RemoteRegistrationSourceManager, FakeAgentCommandRunner, Instance} */
function registrationInspectionFixture(): array
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
function registrationInspectionRow(array $changes = []): array
{
    return array_replace([
        'path' => '/incoming/site', 'layout' => 'checkout', 'repository_url' => 'https://example.test/site.git',
        'branch' => 'main', 'detached' => false, 'commit' => str_repeat('a', 40), 'default_branch' => 'main',
        'inferred_slug' => 'site', 'inferred_root' => null, 'common_repository_path' => '/incoming/site/.git',
        'worktree_paths' => ['/incoming/site'], 'source_digest' => str_repeat('b', 64),
    ], $changes);
}

function registrationInspectionFacts(): RegistrationSourceFacts
{
    return new RegistrationSourceFacts(
        path: '/incoming/site', layout: InstanceSourceLayout::Checkout,
        repositoryUrl: 'https://example.test/site.git', repositoryIdentity: 'example.test/site',
        branch: 'main', detached: false, commit: str_repeat('a', 40), defaultBranch: 'main',
        inferredSlug: 'site', inferredRoot: null, commonRepositoryPath: '/incoming/site/.git',
        worktreePaths: ['/incoming/site'], sourceDigest: str_repeat('b', 64),
    );
}
