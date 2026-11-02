<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]));
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    Http::preventStrayRequests();
});

/** Start a registration, then follow GitHub's redirect back with its one-time code. */
function completeGitHubRegistration(object $test): TestResponse
{
    $test->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
    $state = app(GitHubAppStore::class)->registration()->state;

    return $test->get('/api/v1/github/app/callback?'.http_build_query(['state' => $state, 'code' => 'private-code-sentinel']));
}

function githubAppConversionFixture(): array
{
    return ['id' => 4242, 'slug' => 'orbit-acme', 'name' => 'orbit-acme', 'owner' => ['login' => 'acme', 'type' => 'Organization'],
        'html_url' => 'https://github.com/apps/orbit-acme', 'pem' => GitHubTestSupport::privateKey(), 'client_secret' => 'discarded-upstream-secret'];
}

it('starts no Operation or native run for either App-key command', function (): void {
    Http::fake(['https://api.github.com/app-manifests/private-code-sentinel/conversions' => Http::response(githubAppConversionFixture(), 201),
        'https://api.github.com/app/installations?*' => Http::response([])]);
    completeGitHubRegistration($this)->assertOk();
    $this->postJson('/api/v1/github/app/sync')->assertOk();

    expect(Operation::query()->count())->toBe(0)
        ->and(app(DirectOperation::class)->definition('github.app_register'))->toBeNull()
        ->and(app(DirectOperation::class)->definition('github.app_sync'))->toBeNull();
});

it('converts once in the Gateway and stores only the encrypted key and public identity', function (): void {
    Http::fake(['https://api.github.com/app-manifests/private-code-sentinel/conversions' => Http::response(githubAppConversionFixture(), 201)]);

    completeGitHubRegistration($this)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'registered')->assertJsonPath('data.slug', 'orbit-acme')->assertJsonPath('data.app_id', 4242);

    expect(app(GitHubAppStore::class)->credentials()->privateKey)->toBe(GitHubTestSupport::privateKey())
        ->and(app(GitHubAppStore::class)->registration())->toBeNull()
        ->and(Setting::query()->where('key', 'github.app.private_key')->value('value'))->not->toContain('PRIVATE KEY')
        ->and(json_encode([Setting::query()->where('key', '!=', 'github.app.private_key')->get()->toArray(), Activity::query()->get()->toArray()]))
        ->not->toContain('private-code-sentinel', 'PRIVATE KEY', 'discarded-upstream-secret');
    Http::assertSentCount(1);
});

it('accepts a registration state only once', function (): void {
    Http::fake(['https://api.github.com/app-manifests/*' => Http::response(['message' => 'refused'], 422)]);
    $this->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
    $state = app(GitHubAppStore::class)->registration()->state;
    $callback = '/api/v1/github/app/callback?'.http_build_query(['state' => $state, 'code' => 'private-code-sentinel']);

    $this->get($callback)->assertStatus(502)->assertJsonPath('error.code', 'github.registration_failed');
    $this->get($callback)->assertUnprocessable()->assertJsonPath('error.code', 'github.registration_invalid');
    Http::assertSentCount(1);
});

it('refuses malformed conversion and upstream error bodies without leaking their secrets', function (array $body, int $status): void {
    Http::fake(['https://api.github.com/app-manifests/private-code-sentinel/conversions' => Http::response($body, $status)]);

    $response = completeGitHubRegistration($this)->assertStatus(502)->assertJsonPath('error.code', 'github.registration_failed');

    expect(app(GitHubAppStore::class)->credentials())->toBeNull()
        ->and($response->getContent())->not->toContain('private-upstream-sentinel');
    Http::assertSentCount(1);
})->with(['malformed' => [['id' => 4242, 'pem' => 'private-upstream-sentinel'], 201], 'refused' => [['message' => 'private-upstream-sentinel'], 403]]);

it('refuses an expired or unknown state before sending the code', function (): void {
    $this->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
    $this->get('/api/v1/github/app/callback?'.http_build_query(['state' => 'not-the-state', 'code' => 'private-code-sentinel']))
        ->assertUnprocessable()->assertJsonPath('error.code', 'github.registration_invalid');
    Http::assertNothingSent();
});

it('uses the App JWT only for GitHub and saves only public installation fields', function (): void {
    $this->freezeTime();
    GitHubTestSupport::storeApp();
    $jwt = null;
    Http::fake(['https://api.github.com/app/installations?*' => function (Request $request) use (&$jwt) {
        $jwt = $request->header('Authorization')[0];

        return Http::response([['id' => 9, 'app_id' => 4242, 'account' => ['login' => 'acme', 'type' => 'Organization'],
            'repository_selection' => 'selected', 'suspended_at' => null, 'token' => 'private-token-sentinel']]);
    }]);

    $response = $this->postJson('/api/v1/github/app/sync')->assertOk()
        ->assertJsonPath('data.installations_status', 'current')
        ->assertJsonPath('data.installations', [['id' => 9, 'account' => 'acme', 'type' => 'organization', 'repositories' => 'selected', 'suspended' => false]]);

    $this->getJson('/api/v1/github/app')->assertOk()->assertJsonPath('data.installations.0.account', 'acme');
    expect($jwt)->toStartWith('Bearer ey')
        ->and(json_encode([$response->json(), Setting::query()->where('key', 'github.app.installations')->get()->toArray(), Activity::query()->get()->toArray()]))
        ->not->toContain($jwt, 'PRIVATE KEY', 'private-token-sentinel');
    Http::assertSentCount(1);
});

it('keeps the last observation and reports github.unavailable when a later sync fails', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake(['https://api.github.com/app/installations?*' => Http::sequence()->push([])->push(['message' => 'private-upstream-sentinel'], 500)]);
    $this->postJson('/api/v1/github/app/sync')->assertOk();
    $observation = app(GitHubAppStore::class)->observation();

    $response = $this->postJson('/api/v1/github/app/sync')->assertStatus(503)->assertJsonPath('error.code', 'github.unavailable');

    expect(app(GitHubAppStore::class)->observation())->toBe($observation)
        ->and($response->getContent())->not->toContain('private-upstream-sentinel');
    Http::assertSentCount(2);
});

it('refuses metadata from another App and duplicate installation IDs', function (bool $duplicate): void {
    GitHubTestSupport::storeApp();
    $row = ['id' => 9, 'app_id' => $duplicate ? 4242 : 9999, 'account' => ['login' => 'acme', 'type' => 'Organization'], 'repository_selection' => 'all', 'suspended_at' => null];
    Http::fake(['https://api.github.com/app/installations?*' => Http::response($duplicate ? [$row, $row] : [$row])]);

    $this->postJson('/api/v1/github/app/sync')->assertStatus(503)->assertJsonPath('error.code', 'github.unavailable');

    expect(app(GitHubAppStore::class)->observation())->toBeNull();
    Http::assertSentCount(1);
})->with(['another App' => false, 'duplicate ID' => true]);

it('does not retain metadata when the App is deleted during the GitHub request', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake(['https://api.github.com/app/installations?*' => function () {
        app(GitHubAppStore::class)->delete();

        return Http::response([]);
    }]);

    $this->postJson('/api/v1/github/app/sync');

    expect(app(GitHubAppStore::class)->observation())->toBeNull();
    Http::assertSentCount(1);
});

it('refuses sync without a registered App before any GitHub request', function (): void {
    $this->postJson('/api/v1/github/app/sync')->assertNotFound()->assertJsonPath('error.code', 'github.app_missing');
    Http::assertNothingSent();
});
