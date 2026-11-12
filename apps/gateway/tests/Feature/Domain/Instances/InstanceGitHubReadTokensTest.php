<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
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
});
