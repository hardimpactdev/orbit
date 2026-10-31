<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]));
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    Http::preventStrayRequests();
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'private.fixture']);
});

function admitGitHubRegistration(object $test): Operation
{
    $test->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
    $state = app(GitHubAppStore::class)->registration()->state;
    $response = $test->get('/api/v1/github/app/callback?'.http_build_query(['state' => $state, 'code' => 'private-code-sentinel']))->assertAccepted();
    $op = Operation::query()->findOrFail($response->json('data.id'));
    $op->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $test->runId]]);

    return $op;
}

function githubAppResultBody(object $test, array $result, string $status = 'succeeded'): array
{
    return ['run_id' => $test->runId, 'status' => $status,
        'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z',
        'result' => $result, ...($status === 'failed' ? ['error_code' => 'github.registration_failed'] : [])];
}

it('hands the bound root its private code and stores the existing key type encrypted without public leakage', function (): void {
    $op = admitGitHubRegistration($this);
    $base = '/api/v1/direct-operations/'.$op->id;
    $this->getJson($base.'/private-material?run_id='.$this->runId)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.registration_code', 'private-code-sentinel');
    $this->getJson($base.'?run_id='.$this->runId)->assertOk()->assertDontSee('private-code-sentinel');
    $credentials = GitHubTestSupport::credentials();
    $this->postJson($base.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['app_credentials' => [
        'app_id' => 4242, 'name' => 'orbit-acme', 'slug' => 'orbit-acme', 'owner' => 'acme', 'owner_type' => 'organization',
        'url' => 'https://github.com/apps/orbit-acme', 'private_key' => $credentials->privateKey,
    ]]])->assertOk()->assertDontSee('PRIVATE KEY');
    expect(app(GitHubAppStore::class)->credentials()->privateKey)->toBe($credentials->privateKey);
    expect(Setting::query()->where('key', 'github.app.private_key')->value('value'))->not->toContain('PRIVATE KEY');
    $body = githubAppResultBody($this, ['registered' => true, 'private_key' => 'private-key-sentinel']);
    $this->postJson($base.'/result', $body)->assertOk()->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result', ['registered' => true]);
    $this->postJson($base.'/result', $body)->assertOk();
    expect(app(GitHubAppStore::class)->exchange())->toBeNull();
    expect(json_encode([Operation::query()->get()->toArray(), Activity::query()->get()->toArray()]))
        ->not->toContain('private-code-sentinel', 'private-key-sentinel', 'PRIVATE KEY');
    $this->getJson($base.'/private-material?run_id='.$this->runId)->assertConflict();
    Http::assertNothingSent();
});

it('refuses an unrelated Node and mismatched run before releasing code or accepting a key', function (): void {
    $op = admitGitHubRegistration($this);
    $base = '/api/v1/direct-operations/'.$op->id;
    $foreign = Node::query()->create(['name' => 'foreign', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9', 'status' => LifecycleStatus::Active]);
    $foreign->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.9']);
    $this->getJson($base.'/private-material?run_id='.$this->runId)->assertForbidden();
    $this->postJson($base.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['app_credentials' => 'private-value']])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->getJson($base.'/private-material?run_id=57efb491-8a27-421d-8a24-d1acb7c1fc6a')->assertConflict();
    expect(app(GitHubAppStore::class)->credentials())->toBeNull();
    Http::assertNothingSent();
});

it('removes consumed registration material on native failure and refuses a deleted registration output', function (): void {
    $op = admitGitHubRegistration($this);
    $base = '/api/v1/direct-operations/'.$op->id;
    $this->postJson($base.'/result', githubAppResultBody($this, [], 'failed'))->assertOk();
    expect(app(GitHubAppStore::class)->exchange())->toBeNull();
    expect(app(GitHubAppStore::class)->registration())->toBeNull();
    expect($op->fresh()->status)->toBe(OperationStatus::Failed);
    $this->postJson($base.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['app_credentials' => ['private_key' => 'private-value']]])->assertConflict();
    Http::assertNothingSent();
});

it('mints a short-lived App JWT only to the live bound own-Node sync intent without upstream calls', function (): void {
    $this->freezeTime();
    $credentials = GitHubTestSupport::storeApp();
    $response = $this->postJson('/api/v1/github/app/sync', [])->assertAccepted()->assertJsonPath('data.kind', 'github.app_sync');
    $op = Operation::query()->findOrFail($response->json('data.id'));
    $op->update(['state' => ['swamp_run_id' => $this->runId], 'status' => OperationStatus::Running]);
    $base = '/api/v1/direct-operations/'.$op->id;
    $private = $this->getJson($base.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.app_token.app_id', 4242)->assertJsonPath('data.app_token.expires_at', now()->addSeconds(540)->format('Y-m-d\TH:i:s\Z'));
    $token = $private->json('data.app_token.token');
    [$header, $claims, $signature] = explode('.', $token);
    $decode = static fn (string $value): string => base64_decode(strtr($value, '-_', '+/'));
    $payload = json_decode($decode($claims), true);
    expect($payload)->toBe(['iat' => now()->getTimestamp() - 60, 'exp' => now()->getTimestamp() + 540, 'iss' => '4242']);
    $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($credentials->privateKey))['key'];
    expect(openssl_verify($header.'.'.$claims, $decode($signature), $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
    $this->getJson($base.'?run_id='.$this->runId)->assertOk()->assertDontSee($token);
    expect(json_encode([Operation::query()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain($token, 'PRIVATE KEY');
    $op->update(['cancel_requested_at' => now()]);
    $this->getJson($base.'/private-material?run_id='.$this->runId)->assertForbidden();
    Http::assertNothingSent();
});

it('projects safe installation state once and preserves it when a later sync fails', function (): void {
    $this->freezeTime();
    GitHubTestSupport::storeApp();
    $response = $this->postJson('/api/v1/github/app/sync', [])->assertAccepted();
    $op = Operation::query()->findOrFail($response->json('data.id'));
    $op->update(['state' => ['swamp_run_id' => $this->runId], 'status' => OperationStatus::Running]);
    $result = ['app_id' => 4242, 'observed_at' => now()->toISOString(), 'installations' => [
        ['id' => 9, 'account' => 'acme', 'type' => 'organization', 'repositories' => 'selected', 'suspended' => false, 'token' => 'private-token-sentinel'],
    ]];
    $base = '/api/v1/direct-operations/'.$op->id;
    $body = githubAppResultBody($this, $result);
    $this->postJson($base.'/result', $body)->assertOk()->assertDontSee('private-token-sentinel');
    $this->postJson($base.'/result', $body)->assertOk();
    $this->getJson('/api/v1/github/app')->assertOk()->assertJsonPath('data.installations_status', 'current')
        ->assertJsonPath('data.installations.0.account', 'acme')->assertDontSee('private-token-sentinel');
    $observation = app(GitHubAppStore::class)->observation();
    $later = $this->postJson('/api/v1/github/app/sync', [])->assertAccepted()->json('data.id');
    Operation::query()->findOrFail($later)->update(['state' => ['swamp_run_id' => $this->runId], 'status' => OperationStatus::Running]);
    $this->postJson('/api/v1/direct-operations/'.$later.'/result', githubAppResultBody($this, [], 'failed'))->assertOk();
    expect(app(GitHubAppStore::class)->observation())->toBe($observation);
    Http::assertNothingSent();
});
