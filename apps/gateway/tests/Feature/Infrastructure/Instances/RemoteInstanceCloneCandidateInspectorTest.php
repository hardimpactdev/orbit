<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceCloneCandidateInspector;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

it('returns bound development source evidence from the agent inspection', function (): void {
    $candidate = orb198_clone_candidate();
    $agents = new FakeAgentCommandRunner()->answer('instance.inspect-clone-candidate', ['source' => '/srv/orbit/apps/acme', 'commit' => str_repeat('a', 40)]);

    $source = orb198_clone_candidate_inspector($agents)->inspect($candidate, 'release');

    expect($source->instanceId)->toBe($candidate->id)
        ->and($source->environment)->toBe('development')
        ->and($source->basePath)->toBe('/srv/orbit/apps/acme')
        ->and($source->executionUser)->toBe('orbit')
        ->and($source->branch)->toBe('main')
        ->and($source->commit)->toBe(str_repeat('a', 40))
        ->and($source->node->is($candidate->node))->toBeTrue();
    $input = $agents->calls[0]['input'];
    expect($input['git_read'])->toBeInstanceOf(GitReadEnvironment::class);
    unset($input['git_read']);
    expect($input)->toBe([
        'environment' => 'development',
        'base' => '/srv/orbit/apps/acme',
        'user' => 'orbit',
        'repository' => 'ssh://git@example.test/acme.git',
        'configured_branch' => 'main',
        'target_branch' => 'release',
        'expected_source' => '/srv/orbit/apps/acme',
    ]);
});

it('uses the production runtime identity and configured deployment branch for the selected release', function (): void {
    $candidate = orb198_clone_candidate('production');
    $agents = new FakeAgentCommandRunner()->answer('instance.inspect-clone-candidate', ['source' => '/home/orbit-app-1/releases/20260912010101-one', 'commit' => str_repeat('b', 64)]);

    $source = orb198_clone_candidate_inspector($agents)->inspect($candidate, 'target-release');

    expect($source->environment)->toBe('production')
        ->and($source->basePath)->toBe('/home/orbit-app-1/releases/20260912010101-one')
        ->and($source->executionUser)->toBe('orbit-app-1')
        ->and($source->branch)->toBe('production-release')
        ->and($source->commit)->toBe(str_repeat('b', 64))
        ->and($agents->calls[0]['input'])->toMatchArray([
            'environment' => 'production',
            'base' => '/home/orbit-app-1',
            'user' => 'orbit-app-1',
            'configured_branch' => 'production-release',
            'target_branch' => 'target-release',
            'expected_source' => '/home/orbit-app-1/releases/20260912010101-one',
        ]);
});

it('returns each agent clone refusal as its clone error', function (string $errorCode): void {
    $candidate = orb198_clone_candidate();
    $agents = new FakeAgentCommandRunner()->refuse('instance.inspect-clone-candidate', $errorCode);

    expect(fn () => orb198_clone_candidate_inspector($agents)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception) use ($errorCode): void {
            expect($exception->errorCode)->toBe($errorCode)
                ->and($exception->status)->toBe(409)
                ->and($exception->getMessage())->toBe('The candidate is not eligible for cloning.');
        });
})->with([
    'instance.clone_candidate_dirty',
    'instance.clone_candidate_release_missing',
    'instance.clone_candidate_release_changed',
    'instance.clone_candidate_source_invalid',
    'instance.clone_candidate_branch_invalid',
    'instance.clone_candidate_commit_unavailable',
    'instance.clone_target_branch_missing',
]);

it('redacts URL credentials and token-shaped words from the fetch stderr', function (): void {
    $candidate = orb198_clone_candidate();
    $token = 'ghp_'.str_repeat('a', 36);
    $agents = new FakeAgentCommandRunner()->refuse('instance.inspect-clone-candidate', 'instance.clone_candidate_repository_unavailable', [
        'stderr' => "fatal: unable to access 'https://nick:{$token}@example.test/acme.git/'\nbearer ".str_repeat('Q', 48),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($agents)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception) use ($token): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_repository_unavailable')
                ->and($exception->details['stderr'])->toBe("fatal: unable to access 'https://[REDACTED]@example.test/acme.git/'\nbearer [REDACTED]")
                ->and($exception->getMessage())->not->toContain($token)
                ->and($exception->getMessage())->not->toContain('nick:');
        });
});

it('refuses another refusal, a transport failure, or a malformed answer as unavailable', function (Closure $agents, int $status): void {
    $candidate = orb198_clone_candidate();

    expect(fn () => orb198_clone_candidate_inspector($agents())->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception) use ($status): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_unavailable')
                ->and($exception->status)->toBe($status);
        });
})->with([
    'another refusal' => [fn () => new FakeAgentCommandRunner()->refuse('instance.inspect-clone-candidate', 'agent.deadline_exceeded'), 409],
    'transport failure' => [fn () => new FakeAgentCommandRunner()->answer('instance.inspect-clone-candidate', fn () => throw new RuntimeException('SSH unavailable.')), 502],
    'short commit' => [fn () => new FakeAgentCommandRunner()->answerMalformed('instance.inspect-clone-candidate', ['source' => '/srv/orbit/apps/acme', 'commit' => 'abcd']), 409],
    'uppercase commit' => [fn () => new FakeAgentCommandRunner()->answerMalformed('instance.inspect-clone-candidate', ['source' => '/srv/orbit/apps/acme', 'commit' => str_repeat('A', 40)]), 409],
    'missing source' => [fn () => new FakeAgentCommandRunner()->answerMalformed('instance.inspect-clone-candidate', ['commit' => str_repeat('a', 40)]), 409],
]);

it('refuses a production answer that is not the recorded selected release', function (): void {
    $candidate = orb198_clone_candidate('production');
    $agents = new FakeAgentCommandRunner()->answer('instance.inspect-clone-candidate', ['source' => '/home/orbit-app-1/releases/different', 'commit' => str_repeat('c', 40)]);

    expect(fn () => orb198_clone_candidate_inspector($agents)->inspect($candidate, 'main'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.clone_candidate_source_invalid'));
});

it('refuses invalid candidate and Node identities before it asks the agent', function (string $environment, Closure $mutate): void {
    $candidate = orb198_clone_candidate($environment);
    $mutate($candidate);
    $candidate->save();
    $agents = new FakeAgentCommandRunner;

    expect(fn () => orb198_clone_candidate_inspector($agents)->inspect($candidate, 'main'))
        ->toThrow(ResourceOperationException::class)
        ->and($agents->calls)->toBeEmpty();
})->with([
    'candidate inactive' => ['development', fn (Instance $candidate) => $candidate->status = InstanceState::SourceResolved],
    'candidate lifecycle incomplete' => ['development', fn (Instance $candidate) => $candidate->provisioning_step = 'source-resolved'],
    'Node inactive' => ['development', fn (Instance $candidate) => $candidate->node->update(['status' => LifecycleStatus::Failed])],
    'Node platform unsupported' => ['development', fn (Instance $candidate) => $candidate->node->update(['platform' => 'darwin'])],
    'Node address missing' => ['development', fn (Instance $candidate) => $candidate->node->update(['wireguard_ip' => null])],
    'runtime user invalid' => ['development', fn (Instance $candidate) => $candidate->node->update(['user' => 'Invalid User'])],
    'configured branch missing' => ['development', fn (Instance $candidate) => $candidate->branch = null],
    'source path relative' => ['development', fn (Instance $candidate) => $candidate->checkout_path = 'relative/source'],
    'release layout missing' => ['production', fn (Instance $candidate) => $candidate->checkout_path = '/home/orbit-app-1'],
    'production home missing' => ['production', fn (Instance $candidate) => $candidate->production_home = null],
    'production runtime user missing' => ['production', fn (Instance $candidate) => $candidate->production_user = null],
    'production runtime user invalid' => ['production', fn (Instance $candidate) => $candidate->production_user = 'Invalid User'],
    'production branch missing' => ['production', function (Instance $candidate): void {
        $candidate->branch = null;
        $candidate->deployment_branch = null;
    }],
]);

function orb198_clone_candidate(string $environment = 'development'): Instance
{
    $node = Node::query()->create([
        'name' => "clone-candidate-{$environment}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'role' => $environment === 'production' ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $project = Project::query()->create([
        'name' => 'Acme clone candidate',
        'slug' => 'acme-clone-candidate',
        'repository_url' => 'ssh://git@example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $production = $environment === 'production';

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'candidate',
        'environment' => $environment,
        'source_layout' => 'checkout',
        'checkout_path' => $production
            ? '/home/orbit-app-1/releases/20260912010101-one'
            : '/srv/orbit/apps/acme',
        'production_user' => $production ? 'orbit-app-1' : null,
        'production_home' => $production ? '/home/orbit-app-1' : null,
        'branch' => 'main',
        'deployment_branch' => $production ? 'production-release' : null,
        'starting_commit' => str_repeat('1', 40),
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
}

function orb198_clone_candidate_inspector(FakeAgentCommandRunner $agents): RemoteInstanceCloneCandidateInspector
{
    return new RemoteInstanceCloneCandidateInspector($agents, app(RepositoryReadAccess::class));
}
