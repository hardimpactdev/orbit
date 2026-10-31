<?php

declare(strict_types=1);

use App\Actions\GitHub\RunGitHubAppOperationAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Jobs\Operations\RunGitHubAppOperation;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]));
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    Http::preventStrayRequests();
    Queue::fake([RunGitHubAppOperation::class]);
});

function admitGitHubRegistration(object $test): Operation
{
    $test->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
    $state = app(GitHubAppStore::class)->registration()->state;
    $response = $test->get('/api/v1/github/app/callback?'.http_build_query(['state' => $state, 'code' => 'private-code-sentinel']))->assertAccepted();

    return Operation::query()->findOrFail($response->json('data.id'));
}

function githubAppConversionFixture(): array
{
    return ['id' => 4242, 'slug' => 'orbit-acme', 'name' => 'orbit-acme', 'owner' => ['login' => 'acme', 'type' => 'Organization'],
        'html_url' => 'https://github.com/apps/orbit-acme', 'pem' => GitHubTestSupport::privateKey(), 'client_secret' => 'discarded-upstream-secret'];
}

function admitGitHubSync(object $test, ?string $key = null): Operation
{
    $response = $key === null ? $test->postJson('/api/v1/github/app/sync', [])
        : $test->withHeader('Idempotency-Key', $key)->postJson('/api/v1/github/app/sync', []);
    $response->assertAccepted()->assertJsonPath('data.kind', 'github.app_sync');

    return Operation::query()->findOrFail($response->json('data.id'));
}

it('keeps both App-key kinds off native workflows and exports no private material', function (): void {
    $op = admitGitHubRegistration($this);
    expect(app(DirectOperation::class)->definition('github.app_register'))->toBeNull();
    expect(app(DirectOperation::class)->definition('github.app_sync'))->toBeNull();
    expect(OperationKinds::executor($op->kind))->toBe('gateway');
    expect($op->state)->not->toHaveKey('swamp_start');
    expect(json_encode($op->toArray()))->not->toContain('private-code-sentinel', '$secret');
    Queue::assertPushed(RunGitHubAppOperation::class, fn ($job): bool => $job->operationId === $op->id && $job->connection === 'gateway' && $job->queue === 'operations'
        && ! str_contains(serialize($job), 'private-code-sentinel'));
    Http::assertNothingSent();
});

it('converts once in the Gateway and stores only the encrypted key and public identity', function (): void {
    $op = admitGitHubRegistration($this);
    Http::fake(['https://api.github.com/app-manifests/private-code-sentinel/conversions' => Http::response(githubAppConversionFixture(), 201)]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);
    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($op->result)->toBe(['registered' => true]);
    expect(app(GitHubAppStore::class)->credentials()->privateKey)->toBe(GitHubTestSupport::privateKey());
    expect(Setting::query()->where('key', 'github.app.private_key')->value('value'))->not->toContain('PRIVATE KEY');
    expect(app(GitHubAppStore::class)->exchange())->toBeNull();
    expect(json_encode([Operation::query()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-code-sentinel', 'PRIVATE KEY', 'discarded-upstream-secret');
    Http::assertSentCount(1);
});

it('refuses malformed conversion and upstream error bodies without leaking their secrets', function (array $body, int $status): void {
    $op = admitGitHubRegistration($this);
    Http::fake(['https://api.github.com/app-manifests/private-code-sentinel/conversions' => Http::response($body, $status)]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Failed);
    expect($op->error_code)->toBe('github.registration_failed');
    expect(app(GitHubAppStore::class)->credentials())->toBeNull();
    expect(app(GitHubAppStore::class)->exchange())->toBeNull();
    expect(json_encode($op->toArray()))->not->toContain('private-upstream-sentinel');
    Http::assertSentCount(1);
})->with(['malformed' => [['id' => 4242, 'pem' => 'private-upstream-sentinel'], 201], 'refused' => [['message' => 'private-upstream-sentinel'], 403]]);

it('does not resurrect an App deleted during conversion', function (): void {
    $op = admitGitHubRegistration($this);
    Http::fake(['https://api.github.com/app-manifests/private-code-sentinel/conversions' => function () {
        app(GitHubAppStore::class)->delete();

        return Http::response(githubAppConversionFixture(), 201);
    }]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Failed);
    expect(app(GitHubAppStore::class)->credentials())->toBeNull();
    Http::assertSentCount(1);
});

it('does not send the code for a cancelled registration', function (): void {
    $op = admitGitHubRegistration($this);
    app(CancelOperationAction::class)->execute($op);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect(app(GitHubAppStore::class)->credentials())->toBeNull();
    Http::assertNothingSent();
});

it('uses the App JWT only for GitHub and saves only public installation fields', function (): void {
    $this->freezeTime();
    GitHubTestSupport::storeApp();
    $op = admitGitHubSync($this);
    $jwt = null;
    Http::fake(['https://api.github.com/app/installations?*' => function (Request $request) use (&$jwt) {
        $jwt = $request->header('Authorization')[0];

        return Http::response([['id' => 9, 'app_id' => 4242, 'account' => ['login' => 'acme', 'type' => 'Organization'],
            'repository_selection' => 'selected', 'suspended_at' => null, 'token' => 'private-token-sentinel']]);
    }]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($op->result['installations'])->toBe([['id' => 9, 'account' => 'acme', 'type' => 'organization', 'repositories' => 'selected', 'suspended' => false]]);
    $this->getJson('/api/v1/github/app')->assertOk()->assertJsonPath('data.installations_status', 'current')->assertJsonPath('data.installations.0.account', 'acme');
    expect($jwt)->toStartWith('Bearer ey');
    expect(json_encode([Operation::query()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain($jwt, 'PRIVATE KEY', 'private-token-sentinel');
    Http::assertSentCount(1);
});

it('replays a keyed sync without dispatching a second job', function (): void {
    GitHubTestSupport::storeApp();
    $first = admitGitHubSync($this, 'app-sync-once');
    $second = admitGitHubSync($this, 'app-sync-once');

    expect($second->id)->toBe($first->id);
    Queue::assertPushed(RunGitHubAppOperation::class, 1);
    Http::assertNothingSent();
});

it('preserves the last observation when a later sync fails', function (): void {
    GitHubTestSupport::storeApp();
    $op = admitGitHubSync($this);
    Http::fake(['https://api.github.com/app/installations?*' => Http::sequence()->push([])->push(['message' => 'private-upstream-sentinel'], 500)]);
    app(RunGitHubAppOperationAction::class)->handle($op->id);
    $observation = app(GitHubAppStore::class)->observation();
    $later = admitGitHubSync($this);

    app(RunGitHubAppOperationAction::class)->handle($later->id);

    expect($later->refresh()->status)->toBe(OperationStatus::Failed);
    expect($later->error_code)->toBe('github.app_sync_failed');
    expect(app(GitHubAppStore::class)->observation())->toBe($observation);
    expect(json_encode($later->toArray()))->not->toContain('private-upstream-sentinel');
    Http::assertSentCount(2);
});

it('refuses metadata from another App and duplicate installation IDs', function (bool $duplicate): void {
    GitHubTestSupport::storeApp();
    $op = admitGitHubSync($this);
    $row = ['id' => 9, 'app_id' => $duplicate ? 4242 : 9999, 'account' => ['login' => 'acme', 'type' => 'Organization'], 'repository_selection' => 'all', 'suspended_at' => null];
    Http::fake(['https://api.github.com/app/installations?*' => Http::response($duplicate ? [$row, $row] : [$row])]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Failed);
    expect(app(GitHubAppStore::class)->observation())->toBeNull();
    Http::assertSentCount(1);
})->with(['another App' => false, 'duplicate ID' => true]);

it('does not retain metadata when the App is deleted during the GitHub request', function (): void {
    GitHubTestSupport::storeApp();
    $op = admitGitHubSync($this);
    Http::fake(['https://api.github.com/app/installations?*' => function () {
        app(GitHubAppStore::class)->delete();

        return Http::response([]);
    }]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Failed);
    expect(app(GitHubAppStore::class)->observation())->toBeNull();
    Http::assertSentCount(1);
});

it('cooperates with cancellation received during a metadata request', function (): void {
    GitHubTestSupport::storeApp();
    $op = admitGitHubSync($this);
    Http::fake(['https://api.github.com/app/installations?*' => function () use ($op) {
        app(CancelOperationAction::class)->execute($op->fresh());

        return Http::response([]);
    }]);

    app(RunGitHubAppOperationAction::class)->handle($op->id);

    expect($op->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect(app(GitHubAppStore::class)->observation())->toBeNull();
    Http::assertSentCount(1);
});

it('records a fixed failure if the single-attempt Gateway job times out', function (): void {
    $op = admitGitHubRegistration($this);
    (new RunGitHubAppOperation($op->id))->failed();

    expect($op->refresh()->status)->toBe(OperationStatus::Failed);
    expect($op->error_code)->toBe('github.registration_failed');
    expect(app(GitHubAppStore::class)->exchange())->toBeNull();
    Http::assertNothingSent();
});
