<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\ReadDeadline;
use App\Infrastructure\GitHub\CachedGitHubApi;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;
use function Pest\Laravel\withServerVariables;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
});

/** @return array{Node, Project, Node} */
function branchProject(): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['instance_bridge' => '10.253.0.0/24', 'name' => 'work', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.71', 'wireguard_ip' => '10.44.0.71', 'user' => 'orbit']));
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->saveQuietly();
    $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.72', 'wireguard_ip' => '10.44.0.72', 'user' => 'orbit']));
    $caller->accessibleNodes()->attach($node->id);
    $project = Project::query()->create(['name' => 'Branches', 'slug' => 'branches', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://gitlab.com/example/branches.git', 'default_branch' => 'main', 'root' => '.', 'source_access' => ProjectSourceAccess::GitHubApp]);
    $default = branchInstance($project, $node, 'default');
    InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $default->id, 'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$default->id, 'bridge_address' => '10.253.0.2', 'seed_commit' => str_repeat('c', 40)]);

    return [$caller, $project, $node];
}

function branchInstance(Project $project, Node $node, string $name, ?string $branch = null, ?string $override = null): Instance
{
    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/srv/'.$name, 'status' => InstanceState::Active, 'branch' => $branch, 'branch_override' => $override]);
}

/** @param list<string> $names */
function branchRemote(array $names, int $exit = 0, bool $truncated = false): void
{
    $stdout = implode('', array_map(static fn (string $name): string => str_repeat('a', 40)."\trefs/heads/{$name}\n", $names));
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn (ProcessInvocation $invocation): bool => in_array('ls-remote', $invocation->arguments, true) && $invocation->timeout <= 10 && ! in_array('--exit-code', $invocation->arguments, true)))
        ->andReturn(new CommandResult($exit, $stdout, '', 1, $truncated));
    app()->instance(ProcessRunner::class, $runner);
}

/** @return array{number:int, title:string, html_url:string, state:string, created_at:string, head:array{ref:string,repo:array{full_name:string}}} */
function branchPull(int $number, string $head, string $created = '2026-11-01T00:00:00Z', string $state = 'open'): array
{
    return ['number' => $number, 'title' => 'Pull '.$number, 'html_url' => 'https://github.com/example/branches/pull/'.$number, 'state' => $state, 'created_at' => $created, 'head' => ['ref' => $head, 'repo' => ['full_name' => 'example/branches']]];
}

function branchGitHub(Project $project): void
{
    $project->update(['repository_url' => 'https://github.com/example/branches.git', 'source_access' => ProjectSourceAccess::GhCli]);
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldReceive('token')->once()->andReturn('fixture-token');
    app()->instance(GitHubCliToken::class, $cli);
}

it('lists remote branches with the default first, flags and null unmatched data without a GitHub source', function (): void {
    [$caller, $project] = branchProject();
    branchRemote(['zebra', 'main', 'Alpha']);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.name', 'main')->assertJsonPath('data.0.default', true)
        ->assertJsonPath('data.1', ['name' => 'Alpha', 'default' => false, 'pull_request' => null, 'instance' => null])
        ->assertJsonPath('data.2.name', 'zebra')->assertJsonPath('meta.pull_requests_unavailable', false);
    Http::assertNothingSent();
});

it('filters names by case-insensitive substring before applying the limit', function (): void {
    [$caller, $project] = branchProject();
    branchRemote([...array_map(static fn (int $i): string => sprintf('a%03d', $i), range(1, 105)), 'feature/Login', 'login-fix', 'main']);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches?query=LOGin")
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'feature/Login')->assertJsonPath('data.1.name', 'login-fix');
});

it('limits matches to 100 in stable bytewise order after putting the default first', function (): void {
    [$caller, $project] = branchProject();
    branchRemote([...array_reverse(array_map(static fn (int $i): string => sprintf('b%03d', $i), range(1, 110))), 'main', 'A']);

    $response = branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")->assertOk()->assertJsonCount(100, 'data');
    $response->assertJsonPath('data.*.name', ['main', 'A', ...array_map(static fn (int $i): string => sprintf('b%03d', $i), range(1, 98))]);
});

it('uses recorded branches, overrides and provisioned names and selects the lowest visible Instance id', function (): void {
    [$caller, $project, $node] = branchProject();
    $recorded = branchInstance($project, $node, 'recorded', 'feature', 'wrong');
    branchInstance($project, $node, 'later', 'feature');
    $override = branchInstance($project, $node, 'renamed', override: 'override');
    $named = branchInstance($project, $node, 'named');
    $hidden = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'hidden-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.73', 'wireguard_ip' => '10.44.0.73', 'user' => 'orbit']));
    branchInstance($project, $hidden, 'private-instance', 'hidden');
    branchRemote(['feature', 'override', 'named', 'hidden', 'missing', 'main', 'wrong']);

    $response = branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")->assertOk();
    $response->assertJsonPath('data.1.instance', ['id' => $recorded->id, 'name' => 'recorded'])
        ->assertJsonPath('data.5.instance', ['id' => $override->id, 'name' => 'renamed'])
        ->assertJsonPath('data.4.instance', ['id' => $named->id, 'name' => 'named'])
        ->assertJsonPath('data.0.instance.name', 'default')
        ->assertJsonPath('data.2.instance', null)->assertJsonPath('data.3.instance', null)->assertJsonPath('data.6.instance', null);
    $response->assertDontSee('hidden-node')->assertDontSee('private-instance')->assertDontSee('node_id');
});

it('matches open PRs newest first, ignores closed and foreign heads, and reads once for all branches', function (): void {
    [$caller, $project] = branchProject();
    branchGitHub($project);
    branchRemote(['main', 'feature', 'other', 'none']);
    $foreign = branchPull(99, 'none');
    $foreign['head']['repo']['full_name'] = 'fork/branches';
    Http::fake(['api.github.com/repos/example/branches/pulls*' => Http::response([
        branchPull(1, 'feature'), branchPull(2, 'feature', '2026-11-03T00:00:00Z'), branchPull(3, 'feature', '2026-11-04T00:00:00Z', 'closed'), branchPull(4, 'other'), $foreign,
    ])]);

    $response = branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")->assertOk()->assertJsonPath('meta.pull_requests_unavailable', false);
    $response->assertJsonPath('data.1.pull_request', ['number' => 2, 'title' => 'Pull 2', 'url' => 'https://github.com/example/branches/pull/2'])
        ->assertJsonPath('data.3.pull_request.number', 4)->assertJsonPath('data.2.pull_request', null)->assertJsonPath('data.0.pull_request', null);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['state'] === 'open' && $request['sort'] === 'created' && $request['direction'] === 'desc' && $request['per_page'] === 100 && ! isset($request['head']));
});

it('reads later PR pages and breaks creation-time ties by number', function (): void {
    [$caller, $project] = branchProject();
    branchGitHub($project);
    branchRemote(['feature']);
    Http::fake(['api.github.com/repos/example/branches/pulls*' => Http::sequence()
        ->push([branchPull(1, 'feature')], 200, ['Link' => '<https://api.github.com/repos/example/branches/pulls?page=2>; rel="next"'])
        ->push([branchPull(2, 'feature')])]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.pull_request.number', 2)->assertJsonPath('meta.pull_requests_unavailable', false);
    Http::assertSentCount(2);
});

it('keeps branches and marks PRs unavailable on GitHub failure or malformed data', function (string $failure): void {
    [$caller, $project] = branchProject();
    branchGitHub($project);
    branchRemote(['main', 'feature']);
    Http::fake(['api.github.com/repos/example/branches/pulls*' => match ($failure) {
        'http' => Http::response([], 503),
        'connection' => Http::failedConnection(),
        default => Http::response([['number' => 1]]),
    }]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.pull_request', null)->assertJsonPath('data.1.pull_request', null)->assertJsonPath('meta.pull_requests_unavailable', true);
    Http::assertSentCount(1);
})->with(['http', 'connection', 'malformed']);

it('stops at five PR pages and marks the entire PR projection unavailable on overflow', function (): void {
    [$caller, $project] = branchProject();
    branchGitHub($project);
    branchRemote(['feature']);
    Http::fake(['api.github.com/repos/example/branches/pulls*' => Http::response(array_map(static fn (int $i): array => branchPull($i, 'feature'), range(1, 100)), 200, ['Link' => '<https://api.github.com/repos/example/branches/pulls?page=6>; rel="next"'])]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.pull_request', null)->assertJsonPath('meta.pull_requests_unavailable', true);
    Http::assertSentCount(5);
});

it('returns 403 before reading a remote when the caller cannot reach the default', function (): void {
    [$caller, $project] = branchProject();
    $caller->accessibleNodes()->sync([$caller->id]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches?query[]=bad")
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    Http::assertNothingSent();
});

it('returns 404 for an unknown Project', function (): void {
    [$caller] = branchProject();

    branchRequest($caller, '/api/v1/conn/projects/999999/branches')->assertNotFound();
});

it('returns 422 validation.failed for a malformed query', function (string $query): void {
    [$caller, $project] = branchProject();

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches?".$query)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['query']]]);
})->with(['query[]=bad', 'query='.str_repeat('x', 256), 'query=bad%00name', 'query=main%0A', 'query=%09main', 'query=main%09', 'query=%0Amain', 'query='.str_repeat('%20', 256)]);

it('returns source.head_unavailable when remote output fails or is truncated', function (int $exit, bool $truncated): void {
    [$caller, $project] = branchProject();
    branchRemote(['main'], $exit, $truncated);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable');
})->with([[1, false], [0, true]]);

it('keeps the existing by-head reader one-page all-state behavior through its cache decorator', function (): void {
    Http::fake(['api.github.com/repos/example/branches/pulls*' => Http::response([
        ['number' => 1, 'html_url' => 'https://github.com/example/branches/pull/1', 'state' => 'closed', 'merged_at' => '2026-11-01T00:00:00Z'],
        ['number' => 2, 'html_url' => 'https://github.com/example/branches/pull/2', 'state' => 'open', 'title' => 'Current title'],
        ['number' => 3, 'html_url' => 'https://github.com/example/branches/pull/3', 'state' => 'closed'],
    ], 200, ['Link' => '<https://api.github.com/repos/example/branches/pulls?page=2>; rel="next"'])]);
    $repository = GitHubRepository::fromOrigin('https://github.com/example/branches') ?? throw new RuntimeException('Missing fixture repository.');

    $pulls = app(CachedGitHubApi::class)->pullRequestsByHead('fixture-token', $repository, 'feature');

    expect(array_column($pulls, 'number'))->toBe([1, 2, 3])->and($pulls[0]->state)->toBe(GitHubPullRequestState::Merged)
        ->and($pulls[1]->state)->toBe(GitHubPullRequestState::Open)->and($pulls[1]->title)->toBe('Current title')
        ->and($pulls[2]->state)->toBe(GitHubPullRequestState::Closed);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['head'] === 'example:feature' && $request['state'] === 'all');
});

it('reads with the Project GitHub App installation and a separate PR read token', function (): void {
    [$caller, $project] = branchProject();
    $project->update(['repository_url' => 'https://github.com/example/branches.git']);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(123, 'fixture', 'fixture', 'example', 'organization', 'https://github.com/apps/fixture', 'fixture-key'));
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturn(7);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('fixture-contents');
    $github->shouldReceive('repositoryPullRequestReadToken')->once()->with(Mockery::type(GitHubAppCredentials::class), 7, Mockery::type(GitHubRepository::class), Mockery::type(ReadDeadline::class))->andReturn('fixture-pr-read');
    app()->instance(GitHubApi::class, $github);
    branchRemote(['main']);
    Http::fake(['api.github.com/repos/example/branches/pulls*' => Http::response([branchPull(7, 'main')])]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.pull_request.number', 7)->assertDontSee('fixture-pr-read')->assertDontSee('fixture-contents');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fixture-pr-read'));
});

/** @return TestResponse<Response> */
function branchRequest(Node $caller, string $path): TestResponse
{
    return withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson($path);
}

it('returns an empty list for a readable empty remote without asking GitHub', function (): void {
    [$caller, $project] = branchProject();
    branchGitHub($project);
    branchRemote([]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches?query=")->assertOk()
        ->assertJsonPath('data', [])->assertJsonPath('meta.pull_requests_unavailable', false);
    Http::assertNothingSent();
});

it('returns source.head_unavailable for malformed remote refs', function (string $name): void {
    [$caller, $project] = branchProject();
    branchRemote([$name]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable');
})->with(['invalid branch', 'bad..name', 'x.lock', 'a//b']);

it('lists long valid remote branch names without applying the creation input limit', function (): void {
    [$caller, $project] = branchProject();
    $name = str_repeat('nested/', 40).'feature';
    branchRemote([$name]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.name', $name);
});

it('returns 422 for leading and trailing spaces instead of trimming the substring', function (): void {
    [$caller, $project] = branchProject();

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches?query=%20main%20")
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
});

it('returns 409 instance.default_missing when the Project default is not ready', function (): void {
    [$caller, $project, $node] = branchProject();
    InstanceRuntime::query()->where('node_id', $node->id)->update(['seed_commit' => null]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertStatus(409)->assertJsonPath('error.code', 'instance.default_missing');
    Http::assertNothingSent();
});

it('includes slow App credential acquisition in each read budget and reuses its installation', function (): void {
    freezeTime();
    [$caller, $project] = branchProject();
    $project->update(['repository_url' => 'https://github.com/example/branches.git']);
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]) ?: throw new RuntimeException('No key.'), $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(123, 'fixture', 'fixture', 'example', 'organization', 'https://github.com/apps/fixture', $pem));
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn (ProcessInvocation $invocation): bool => $invocation->timeout === 5.0 && $invocation->arguments[0] === 'git'))
        ->andReturn(new CommandResult(0, str_repeat('a', 40)."\trefs/heads/main\n", '', 1, false));
    app()->instance(ProcessRunner::class, $runner);
    Http::fake([
        'api.github.com/repos/example/branches/installation' => function (Request $request, array $options) {
            expect($options['timeout'])->toBe(10.0)->and($options['connect_timeout'])->toBe(10.0)->and($options['allow_redirects'])->toBeFalse();
            travel(2)->seconds();

            return Http::response(['id' => 7]);
        },
        'api.github.com/app/installations/7/access_tokens' => function (Request $request, array $options) {
            $permissions = $request['permissions'];
            $contents = is_array($permissions) && isset($permissions['contents']);
            expect($options['timeout'])->toBe($contents ? 8.0 : 10.0);
            travel($contents ? 3 : 8)->seconds();

            return Http::response(['token' => $contents ? 'fixture-contents' : 'fixture-pr-read'], 201);
        },
        'api.github.com/repos/example/branches/pulls*' => function (Request $request, array $options) {
            expect($options['timeout'])->toBe(2.0);
            expect($request->hasHeader('Authorization', 'Bearer fixture-pr-read'))->toBeTrue();

            return Http::response([branchPull(7, 'main')]);
        },
    ]);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.pull_request.number', 7)->assertJsonPath('meta.pull_requests_unavailable', false);
    Http::assertSentCount(4);
});

it('returns source.head_unavailable when slow source credential acquisition exhausts its deadline', function (string $phase): void {
    freezeTime();
    [$caller, $project] = branchProject();
    $project->update(['repository_url' => 'https://github.com/example/branches.git']);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(123, 'fixture', 'fixture', 'example', 'organization', 'https://github.com/apps/fixture', 'fixture-key'));
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturnUsing(static function (GitHubAppCredentials $credentials, GitHubRepository $repository, ReadDeadline $deadline) use ($phase): int {
        expect($deadline->remaining())->toBe(10.0);
        if ($phase === 'installation') {
            travel(11)->seconds();
        }

        return 7;
    });
    if ($phase === 'token') {
        $github->shouldReceive('repositoryReadToken')->once()->andReturnUsing(static function (GitHubAppCredentials $credentials, int $installation, GitHubRepository $repository, ReadDeadline $deadline): string {
            expect($deadline->remaining())->toBe(10.0);
            travel(11)->seconds();

            return 'fixture-contents';
        });
    }
    app()->instance(GitHubApi::class, $github);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable');
    Http::assertNothingSent();
})->with(['installation', 'token']);

it('keeps branches when slow PR credential acquisition exhausts the PR deadline', function (): void {
    freezeTime();
    [$caller, $project] = branchProject();
    $project->update(['repository_url' => 'https://github.com/example/branches.git']);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(123, 'fixture', 'fixture', 'example', 'organization', 'https://github.com/apps/fixture', 'fixture-key'));
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturn(7);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('fixture-contents');
    $github->shouldReceive('repositoryPullRequestReadToken')->once()->andReturnUsing(static function (GitHubAppCredentials $credentials, int $installation, GitHubRepository $repository, ReadDeadline $deadline): string {
        expect($deadline->remaining())->toBe(10.0);
        travel(11)->seconds();

        return 'fixture-pr-read';
    });
    app()->instance(GitHubApi::class, $github);
    branchRemote(['main']);

    branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches")
        ->assertOk()->assertJsonPath('data.0.name', 'main')->assertJsonPath('data.0.pull_request', null)->assertJsonPath('meta.pull_requests_unavailable', true);
    Http::assertNothingSent();
});

it('bounds CLI credential acquisition and reuses its token for the PR read', function (int $credentialSeconds): void {
    freezeTime();
    [$caller, $project] = branchProject();
    $project->update(['repository_url' => 'https://github.com/example/branches.git', 'source_access' => ProjectSourceAccess::GhCli]);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn (ProcessInvocation $invocation): bool => $invocation->arguments[0] === 'gh' && $invocation->timeout === 10.0))
        ->andReturnUsing(static function () use ($credentialSeconds): CommandResult {
            travel($credentialSeconds)->seconds();

            return new CommandResult(0, str_repeat('a', 24), '', 1, false);
        });
    if ($credentialSeconds < 10) {
        $runner->shouldReceive('run')->once()->with(Mockery::on(static fn (ProcessInvocation $invocation): bool => $invocation->arguments[0] === 'git' && $invocation->timeout === 7.0))
            ->andReturn(new CommandResult(0, str_repeat('a', 40)."\trefs/heads/main\n", '', 1, false));
        Http::fake(['api.github.com/repos/example/branches/pulls*' => Http::response([branchPull(7, 'main')])]);
    }
    app()->instance(ProcessRunner::class, $runner);

    $response = branchRequest($caller, "/api/v1/conn/projects/{$project->id}/branches");
    if ($credentialSeconds < 10) {
        $response->assertOk()->assertJsonPath('data.0.pull_request.number', 7);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.str_repeat('a', 24)));
    } else {
        $response->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable');
        Http::assertNothingSent();
    }
})->with([3, 11]);

it('preserves the original CLI token and exact-head timeout defaults', function (): void {
    [, $project] = branchProject();
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn (ProcessInvocation $invocation): bool => $invocation->arguments[0] === 'gh' && $invocation->timeout === 30.0))
        ->andReturn(new CommandResult(0, str_repeat('a', 24), '', 1, false));
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn (ProcessInvocation $invocation): bool => $invocation->arguments[0] === 'git' && $invocation->timeout === 60.0 && in_array('--exit-code', $invocation->arguments, true)))
        ->andReturn(new CommandResult(0, str_repeat('a', 40)."\trefs/heads/main\n", '', 1, false));
    app()->instance(ProcessRunner::class, $runner);

    expect(app(GitHubCliToken::class)->token())->toBe(str_repeat('a', 24))
        ->and(app(GitRemoteBranchHeads::class)->head($project, 'main'))->toBe(str_repeat('a', 40));
    Http::assertNothingSent();
});
