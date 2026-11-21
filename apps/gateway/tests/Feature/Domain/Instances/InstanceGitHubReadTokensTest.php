<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\CloneStage;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'fixture']);
    $this->sourceSocket = new class implements WebSocketClient
    {
        public array $frames = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->sourceSocket);
    static $pem = null;
    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);
    }
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'orbit-nckrtl', 'Orbit', 'nckrtl', 'user',
        'https://github.com/apps/orbit-nckrtl', $pem));
});

it('refuses a missing installation or excluded private repository before any vault copy', function (bool $installation): void {
    Http::fake([
        'api.github.com/repos/Platform11/servauto-app/installation' => Http::response($installation ? ['id' => 7] : [], $installation ? 200 : 404),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['message' => 'Repository not in installation'], 422),
        'api.github.com/repos/Platform11/servauto-app' => Http::response([], 404),
    ]);

    try {
        app(InstanceGitHubReadTokens::class)->credentialCopy('https://github.com/Platform11/servauto-app.git', 'fixture');
        $this->fail('Inaccessible private source passed credential preparation.');
    } catch (ResourceOperationException $error) {
        expect($this->sourceSocket->frames)->toBe([]);
        expect($error->errorCode)->toBe('source.github_app_denied')->and($error->status)->toBe(403)
            ->and($error->getMessage())->toBe('The GitHub App orbit-nckrtl cannot read Platform11/servauto-app: install it for that organisation or add the repository.');
    }
})->with(['no installation' => false, 'repository excluded at token mint' => true]);

it('distinguishes GitHub unavailability from absent installation without writing a vault copy', function (string $phase): void {
    Http::fake(function ($request) use ($phase) {
        if (str_ends_with($request->url(), '/installation')) {
            if ($phase === 'installation timeout') {
                throw new ConnectionException('fixture timeout');
            }

            return Http::response($phase === 'mint' ? ['id' => 7] : [], $phase === 'installation 5xx' ? 503 : ($phase === 'mint' ? 200 : 404));
        }

        return Http::response([], 503);
    });

    try {
        app(InstanceGitHubReadTokens::class)->credentialCopy('https://github.com/Platform11/servauto-app.git', 'fixture');
        $this->fail('GitHub failure passed credential preparation.');
    } catch (ResourceOperationException $error) {
        expect($this->sourceSocket->frames)->toBe([]);
        expect($error->errorCode)->toBe('source.github_unavailable')->and($error->status)->toBe(502)
            ->and($error->getMessage())->toBe('GitHub could not supply a source credential for Platform11/servauto-app.');
    }
})->with(['installation 5xx', 'installation timeout', 'mint', 'public lookup']);

it('preserves anonymous public source access after positively checking visibility', function (): void {
    Http::fake([
        'api.github.com/repos/laravel/laravel/installation' => Http::response([], 404),
        'api.github.com/repos/laravel/laravel' => Http::response(['full_name' => 'laravel/laravel', 'private' => false]),
    ]);

    expect(app(InstanceGitHubReadTokens::class)->credentialCopy('https://github.com/laravel/laravel.git', 'fixture'))
        ->toBe(['vault' => 'orbit-control-inputs', 'key' => 'fixture'])
        ->and($this->sourceSocket->frames[0]['payload']['value'])->toBe('');
});

it('copies only a repository-scoped Contents read credential when the App has access', function (): void {
    Http::fake([
        'api.github.com/repos/Platform11/servauto-app/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'fixture_token',
            'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read']], 201),
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 1,
            'repositories' => [['full_name' => 'Platform11/servauto-app']]]),
    ]);

    app(InstanceGitHubReadTokens::class)->credentialCopy('https://github.com/Platform11/servauto-app.git', 'fixture');
    expect($this->sourceSocket->frames[0]['payload']['value'])->toBe("https://x-access-token:fixture_token@github.com\n");
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['servauto-app'], 'permissions' => ['contents' => 'read']]);
    // Resolving the installation once avoids a second `/installation` round-trip inside scopedRepositoryToken().
    Http::assertSentCount(3);
});

/** A clone's mint (copy) with its public stage, for a private repository the App covers. */
function cloneMint(array $stage): array
{
    Http::fake([
        'api.github.com/repos/Platform11/servauto-app/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'fixture_token',
            'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read']], 201),
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'Platform11/servauto-app']]]),
        'api.github.com/installation/token' => Http::response(null, 204),
    ]);
    $operation = new Operation;
    $operation->forceFill(['id' => 31]);
    $copy = new ReflectionMethod(InstanceGitHubReadTokens::class, 'copy');

    return [$copy, $operation, fn () => $copy->invoke(app(InstanceGitHubReadTokens::class), $operation, 'https://github.com/Platform11/servauto-app.git',
        fn (): bool => true, "#orbit-model-environment\nORBIT_MODEL_KEY=placeholder\n", $stage)];
}

it('writes a private repository\'s token only into the clone copy and the one-line launch copy', function (): void {
    [, , $mint] = cloneMint(['script' => 'print(1)']);

    $mint();

    $puts = collect($this->sourceSocket->frames)->mapWithKeys(fn (array $frame): array => [$frame['payload']['key'] => $frame['payload']['value']]);
    $launch = json_decode($puts['instance-create-launch-31'], true, flags: JSON_THROW_ON_ERROR);
    expect($puts->keys()->sort()->values()->all())->toBe(['instance-create-clone-31', 'instance-create-launch-31', 'instance-git-operation-31'])
        ->and($puts['instance-create-launch-31'])->not->toContain("\n")
        ->and($launch)->toBe(['stage' => ['script' => 'print(1)'],
            'private' => "https://x-access-token:fixture_token@github.com\n#orbit-model-environment\nORBIT_MODEL_KEY=placeholder\n"])
        ->and($puts['instance-create-clone-31'])->toBe($launch['private']);
});

it('refuses a launch copy over its cap by name and revokes the token it minted', function (): void {
    [, , $mint] = cloneMint(['script' => str_repeat('x', CloneStage::MaxBytes)]);

    try {
        $mint();
        $this->fail('An oversize launch copy passed.');
    } catch (ResourceOperationException $refused) {
        expect([$refused->errorCode, $refused->status])->toBe(['instance.clone_launch_too_large', 409])
            ->and(collect($this->sourceSocket->frames)->pluck('payload.key')->all())->not->toContain('instance-create-launch-31');
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/installation/token'));
    }
});

/** An admitted app-dev create Operation for testing engine read-token caching. */
function engineReadOperation(string $repository = 'https://github.com/Platform11/servauto-app.git'): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture',
    ]));
    NodeRole::query()->create(['node_id' => $node->id, 'role' => 'app-dev', 'status' => 'active']);
    $identity = GitRepositoryIdentity::derive($repository);
    $project = Project::query()->create([
        'name' => 'Servauto', 'slug' => 'servauto-'.$node->id, 'repository_url' => $repository,
        'repository_identity' => $identity, 'source_access' => ProjectSourceAccess::GitHubApp,
        'default_branch' => 'main', 'type' => ProjectType::LaravelApp,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'branch' => 'main',
        'checkout_path' => '/srv/workspace',
    ]);

    return (new Operation)->forceFill([
        'id' => 91, 'kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $node->id,
        'input' => ['project_id' => $project->id, 'instance_id' => $instance->id, 'repository' => $repository, 'repository_identity' => $identity],
    ]);
}

it('caches scope-verified read tokens for engine fetches and re-mints after forget', function (): void {
    $mints = 0;
    Http::fake([
        'api.github.com/repos/Platform11/servauto-app/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => function () use (&$mints) {
            $mints++;

            return Http::response(['token' => "cached_token_{$mints}",
                'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read']], 201);
        },
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 1,
            'repositories' => [['full_name' => 'Platform11/servauto-app']]]),
    ]);

    $operation = engineReadOperation();
    $tokens = app(InstanceGitHubReadTokens::class);

    // Cold call: installation + access_tokens + repository scope check (3 HTTPS calls, no duplicate /installation).
    expect($tokens->line($operation))->toBe("https://x-access-token:cached_token_1@github.com\n");
    Http::assertSentCount(3);

    // Warm call: zero HTTPS calls to GitHub.
    expect($tokens->line($operation))->toBe("https://x-access-token:cached_token_1@github.com\n");
    Http::assertSentCount(3);

    // Forgetting after a failed fetch clears the cache so the next create mints fresh credentials.
    $tokens->forget($operation);
    expect($tokens->line($operation))->toBe("https://x-access-token:cached_token_2@github.com\n");
    Http::assertSentCount(6);
});

it('recovers when a cached installation ID is refused by refreshing from GitHub once', function (): void {
    $installationLookups = 0;
    Http::fake([
        'api.github.com/repos/Platform11/servauto-app/installation' => function () use (&$installationLookups) {
            $installationLookups++;

            return Http::response(['id' => $installationLookups === 1 ? 7 : 8]);
        },
        'api.github.com/app/installations/7/access_tokens' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/app/installations/8/access_tokens' => Http::response(['token' => 'fresh_token',
            'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read']], 201),
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 1,
            'repositories' => [['full_name' => 'Platform11/servauto-app']]]),
    ]);

    $operation = engineReadOperation();
    // Seed a stale cached installation ID 7 before calling line().
    app(GitHubTokenCache::class)->installation(
        4242,
        GitHubRepository::fromOrigin('https://github.com/Platform11/servauto-app.git'),
        static fn (): int => 7,
    );
    $installationLookups = 1;

    expect(app(InstanceGitHubReadTokens::class)->line($operation))->toBe("https://x-access-token:fresh_token@github.com\n")
        ->and($installationLookups)->toBe(2);
});
