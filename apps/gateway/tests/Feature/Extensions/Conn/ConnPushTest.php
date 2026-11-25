<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\Managed\EnrolConnManagedTargetAction;
use Orbit\Extensions\Conn\Domain\ConnNodeRemoval;
use Orbit\Extensions\Conn\Domain\ConnOperationCompletion;
use Orbit\Extensions\Conn\Domain\Push\ApnsClient;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;
use Orbit\Extensions\Conn\Infrastructure\FakeApnsClient;
use Orbit\Extensions\Conn\Jobs\SendConnPushJob;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Orbit\Extensions\Conn\Models\ConnPushDevice;
use Orbit\Extensions\Conn\Models\ConnThread;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

function push_node(string $name, string $ip, string $status = 'active'): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
        'user' => 'orbit',
    ]));
}

function push_environment(string $environmentId, Node $node, ?int $instanceId = null): ConnEnvironment
{
    $env = ConnEnvironment::query()->create([
        'environment_id' => $environmentId,
        'label' => 'env-'.$environmentId,
        'url' => 'http://'.$node->wireguard_ip.':3773',
        'node_id' => $node->id,
        'admin_session' => 'admin-token',
        'admin_session_expires_at' => Carbon::now()->addDays(30),
        'registered_at' => Carbon::now(),
    ]);
    if ($instanceId !== null) {
        $env->forceFill(['instance_id' => $instanceId])->save();
    }

    return $env;
}

/** @return array{0: ConnManagedTarget, 1: string} */
function push_enrol_managed(string $environmentId): array
{
    $file = tempnam(sys_get_temp_dir(), 'conn-push-svc-');
    assert(is_string($file));
    app(EnrolConnManagedTargetAction::class)->execute($environmentId, $file);
    $credential = (string) file_get_contents($file);
    unlink($file);

    return [ConnManagedTarget::query()->where('environment_id', $environmentId)->firstOrFail(), $credential];
}

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function push_device_payload(array $overrides = []): array
{
    return array_replace_recursive([
        'device_id' => (string) Str::uuid(),
        'apns_token' => str_repeat('ab', 32),
        'apns_environment' => 'sandbox',
        'bundle_id' => 'dev.conn.ios',
        'app_version' => '1.2.3',
        'ios_version' => '18.4',
        'locale' => 'en_US',
        'preferences' => [
            'approval' => true,
            'input' => true,
            'turn_finished' => true,
            'turn_failed' => true,
            'hide_titles' => false,
        ],
    ], $overrides);
}

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function push_attention_payload(string $environmentId, array $overrides = []): array
{
    return array_merge([
        'event_id' => (string) Str::ulid(),
        'type' => 'attention',
        'kind' => 'approval',
        'environment_id' => $environmentId,
        'thread_id' => 'thread-1',
        'request_id' => 'req-1',
        'occurred_at' => Carbon::now()->toIso8601String(),
        'thread_title' => 'Fix login bug',
    ], $overrides);
}

/** @param array<string, mixed> $body */
function push_call(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/conn/'.$path, $body);
}

/** @param array<string, mixed> $body */
function push_managed_call(Node $peer, string $credential, ConnManagedTarget $target, array $body, ?int $generation = null): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])
        ->withToken($credential)
        ->postJson('/api/v1/conn/managed/attention', [
            'generation' => $generation ?? $target->generation,
            ...$body,
        ]);
}

beforeEach(function (): void {
    Queue::fake();
    app(ExtensionStore::class)->set('conn', true);
    config(['orbit.conn.push' => true]);
    config(['orbit.conn.managed_targets' => true]);
    $this->apns = new FakeApnsClient;
    app()->instance(ApnsClient::class, $this->apns);

    $this->gateway = push_node('gateway', '10.44.0.2');
    NodeRole::withoutEvents(fn () => $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
    $this->serving = push_node('mac', '10.44.0.10');
    $this->otherServing = push_node('linux-box', '10.44.0.11');
    $this->phone = push_node('iphone', '10.44.0.20');
    $this->otherPhone = push_node('iphone-2', '10.44.0.21');
    $this->stranger = push_node('stranger', '10.44.0.22');
    $this->phone->accessibleNodes()->attach($this->serving->id);
    $this->otherPhone->accessibleNodes()->attach($this->serving->id);
    $this->environmentId = '6f1c2e9a-3b4d-4c5e-8f60-718293a4b5c6';
    $this->environment = push_environment($this->environmentId, $this->serving);
});

describe('feature flag', function (): void {
    it('ships off by default and hides every push route when disabled', function (): void {
        expect(ConnPush::enabled())->toBeTrue();
        config(['orbit.conn.push' => false]);
        expect(ConnPush::enabled())->toBeFalse();

        $deviceId = (string) Str::uuid();
        push_call($this->phone, 'PUT', 'me/push-device', push_device_payload(['device_id' => $deviceId]))
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');
        push_call($this->phone, 'GET', 'me/push-device?device_id='.$deviceId)
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');
        push_call($this->phone, 'DELETE', 'me/push-device/'.$deviceId)
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');
        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId))
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');

        [$target, $credential] = push_enrol_managed($this->environmentId);
        push_managed_call($this->serving, $credential, $target, push_attention_payload($this->environmentId))
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');
        push_call($this->gateway, 'GET', 'push-credentials')
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');
        push_call($this->gateway, 'PUT', 'push-credentials', ['key_id' => 'KEY1234567', 'team_id' => 'TEAM123456', 'private_key' => 'invalid'])
            ->assertNotFound()->assertJsonPath('error.code', 'conn.push_disabled');
    });

    it('defaults orbit.conn.push to false in config/orbit.php', function (): void {
        $defaults = require base_path('config/orbit.php');
        expect($defaults['conn']['push'])->toBeFalse();
    });
});

describe('device routes', function (): void {
    it('enforces WireGuard peer, node access and conn extension on all device routes', function (): void {
        $deviceId = (string) Str::uuid();
        $payload = push_device_payload(['device_id' => $deviceId]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99'])->putJson('/api/v1/conn/me/push-device', $payload)
            ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
        push_call($this->stranger, 'PUT', 'me/push-device', $payload)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        push_call($this->stranger, 'GET', 'me/push-device?device_id='.$deviceId)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        push_call($this->stranger, 'DELETE', 'me/push-device/'.$deviceId)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

        app(ExtensionStore::class)->set('conn', false);
        push_call($this->phone, 'PUT', 'me/push-device', $payload)
            ->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    });

    it('upserts, encrypts the token, never echoes it in responses or activity, reads and deletes', function (): void {
        $deviceId = (string) Str::uuid();
        $token1 = str_repeat('a1', 32);
        $token2 = str_repeat('b2', 32);

        $put1 = push_call($this->phone, 'PUT', 'me/push-device', push_device_payload([
            'device_id' => $deviceId,
            'apns_token' => $token1,
            'apns_environment' => 'sandbox',
        ]));
        $put1->assertOk()
            ->assertJsonPath('data.node_id', $this->phone->id)
            ->assertJsonPath('data.device_id', $deviceId)
            ->assertJsonPath('data.apns_environment', 'sandbox')
            ->assertJsonPath('data.bundle_id', 'dev.conn.ios')
            ->assertJsonPath('data.preferences.hide_titles', false);
        expect($put1->getContent())->not->toContain($token1);

        $raw = DB::table('conn_push_devices')->where('node_id', $this->phone->id)->where('device_id', $deviceId)->value('apns_token');
        expect($raw)->toBeString()->not->toBe($token1)->not->toContain($token1);
        expect(ConnPushDevice::query()->firstOrFail()->apns_token)->toBe($token1);

        // Activity must never contain the APNs token.
        $activity = Activity::query()->where('command', 'conn:me:push-device:upsert')->latest('id')->first();
        expect($activity)->not->toBeNull();
        expect(json_encode($activity->input))->not->toContain($token1);

        // Second PUT replaces the token and updates preferences in place.
        push_call($this->phone, 'PUT', 'me/push-device', push_device_payload([
            'device_id' => $deviceId,
            'apns_token' => $token2,
            'apns_environment' => 'production',
            'preferences' => ['hide_titles' => true],
        ]))->assertOk()->assertJsonPath('data.apns_environment', 'production')->assertJsonPath('data.preferences.hide_titles', true);

        expect(ConnPushDevice::query()->count())->toBe(1)
            ->and(ConnPushDevice::query()->firstOrFail()->apns_token)->toBe($token2);

        // GET returns the row without the token; another Node gets 404.
        $get = push_call($this->phone, 'GET', 'me/push-device?device_id='.$deviceId);
        $get->assertOk()->assertJsonPath('data.device_id', $deviceId)->assertJsonPath('data.apns_environment', 'production');
        expect($get->getContent())->not->toContain($token2);

        push_call($this->otherPhone, 'GET', 'me/push-device?device_id='.$deviceId)->assertNotFound();

        // Another Node cannot delete this Node's device.
        push_call($this->otherPhone, 'DELETE', 'me/push-device/'.$deviceId)
            ->assertOk()->assertJsonPath('data.removed', false);
        expect(ConnPushDevice::query()->count())->toBe(1);

        // Owning Node deletes it; repeat is idempotent.
        push_call($this->phone, 'DELETE', 'me/push-device/'.$deviceId)
            ->assertOk()->assertJsonPath('data.removed', true);
        push_call($this->phone, 'DELETE', 'me/push-device/'.$deviceId)
            ->assertOk()->assertJsonPath('data.removed', false);
        expect(ConnPushDevice::query()->count())->toBe(0);
    });

    it('validates device registration fields', function (): void {
        $response = push_call($this->phone, 'PUT', 'me/push-device', push_device_payload([
            'device_id' => 'not-a-uuid',
            'apns_token' => 'zzzz-not-hex',
            'apns_environment' => 'staging',
            'preferences' => ['approval' => 'yes'],
        ]))->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect(array_keys($response->json('error.details')))
            ->toContain('device_id', 'apns_token', 'apns_environment', 'preferences.approval');
    });
});

describe('attention routes', function (): void {
    it('accepts native attention events, enforces server access, redacts titles from activity, and queues SendConnPushJob', function (): void {
        push_environment('env-other', $this->otherServing);

        // Phone has access to serving ($this->environmentId) but NOT otherServing (env-other).
        push_call($this->phone, 'POST', 'environments/env-other/attention', push_attention_payload('env-other'))
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        push_call($this->stranger, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId))
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

        $payload = push_attention_payload($this->environmentId, ['thread_title' => 'Secret customer bug']);
        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.event_id', $payload['event_id'])
            ->assertJsonPath('data.deduplicated', false);

        Queue::assertPushedOn(GatewayQueue::Tasks->value, SendConnPushJob::class);
        Queue::assertPushed(SendConnPushJob::class, 1);

        $activity = Activity::query()->where('command', 'conn:attention:record')->latest('id')->first();
        expect($activity)->not->toBeNull();
        expect(json_encode($activity->input))->not->toContain('Secret customer bug');
    });

    it('accepts managed attention events behind AuthenticateConnManagedTarget and records no activity', function (): void {
        [$target, $credential] = push_enrol_managed($this->environmentId);
        $payload = push_attention_payload($this->environmentId);

        // Wrong bearer -> 403; wrong peer -> 403; wrong generation -> 409.
        push_managed_call($this->serving, 'conn_svc_wrong', $target, $payload)
            ->assertForbidden()->assertJsonPath('error.code', 'conn.managed_target_identity');
        push_managed_call($this->phone, $credential, $target, $payload)
            ->assertForbidden()->assertJsonPath('error.code', 'conn.managed_target_identity');
        push_managed_call($this->serving, $credential, $target, $payload, generation: $target->generation + 1)
            ->assertConflict()->assertJsonPath('error.code', 'conn.managed_generation_mismatch');

        $activityBefore = Activity::query()->count();
        push_managed_call($this->serving, $credential, $target, $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.event_id', $payload['event_id'])
            ->assertJsonPath('data.deduplicated', false);

        expect(Activity::query()->count())->toBe($activityBefore);
        Queue::assertPushedOn(GatewayQueue::Tasks->value, SendConnPushJob::class);
        Queue::assertPushed(SendConnPushJob::class, 1);
    });

    it('validates environment_id match, thread ownership, kind rules, title length and ULID', function (): void {
        // Mismatched environment_id.
        $mismatch = push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload('env-other'))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(array_keys($mismatch->json('error.details')))->toContain('environment_id');

        // Thread belonging to another environment in conn_threads.
        $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
        $instance = Instance::query()->create([
            'node_id' => $this->otherServing->id,
            'project_id' => $project->id,
            'name' => 'other-clone',
            'checkout_path' => '/workspace/other-clone',
            'root' => 'public',
            'status' => 'active',
            'first_setup_pending' => false,
            'source_is_laravel' => true,
        ]);
        $otherEnv = push_environment('env-other-clone', $this->otherServing, $instance->id);
        ConnThread::query()->create([
            'conn_environment_id' => $otherEnv->id,
            'instance_id' => $instance->id,
            'thread_id' => 'thread-belongs-to-other',
            'title' => 'Other thread',
            'seen_at' => Carbon::now(),
        ]);

        $wrongThread = push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId, [
            'thread_id' => 'thread-belongs-to-other',
        ]))->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(array_keys($wrongThread->json('error.details')))->toContain('thread_id');

        $otherHost = push_environment('env-other-host', $this->otherServing);
        ConnThread::hostQuery()->create(['conn_environment_id' => $otherHost->id, 'thread_id' => 'thread-belongs-to-other-host',
            'title' => 'Other host thread', 'seen_at' => now()]);
        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId, [
            'thread_id' => 'thread-belongs-to-other-host',
        ]))->assertUnprocessable()->assertJsonValidationErrors('thread_id', 'error.details');

        // attention_cleared with non-null kind fails; with null kind succeeds.
        $badCleared = push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId, [
            'type' => 'attention_cleared',
            'kind' => 'approval',
        ]))->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(array_keys($badCleared->json('error.details')))->toContain('kind');

        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId, [
            'type' => 'attention_cleared',
            'kind' => null,
            'thread_title' => null,
        ]))->assertStatus(202);

        // Title > 64 chars and invalid ULID fail.
        $badFields = push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId, [
            'event_id' => 'not-a-ulid',
            'thread_title' => str_repeat('x', 65),
        ]))->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(array_keys($badFields->json('error.details')))->toContain('event_id', 'thread_title');
    });

    it('deduplicates repeated event_id with HTTP 200 and no second job', function (): void {
        $payload = push_attention_payload($this->environmentId);

        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", $payload)
            ->assertStatus(202)->assertJsonPath('data.deduplicated', false);
        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", $payload)
            ->assertOk()->assertJsonPath('data.deduplicated', true);

        expect(ConnAttentionEvent::query()->count())->toBe(1);
        Queue::assertPushedOn(GatewayQueue::Tasks->value, SendConnPushJob::class);
        Queue::assertPushed(SendConnPushJob::class, 1);
    });

    it('rate limits each server at 120 events per minute with 429', function (): void {
        $rateKey = 'conn:push:attention:'.$this->environment->id;
        RateLimiter::clear($rateKey);
        for ($i = 0; $i < ConnPush::MaxEventsPerMinute; $i++) {
            RateLimiter::hit($rateKey, 60);
        }

        push_call($this->phone, 'POST', "environments/{$this->environmentId}/attention", push_attention_payload($this->environmentId))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'conn.attention_rate_limited');

        // A different server still has its own budget.
        $this->phone->accessibleNodes()->attach($this->otherServing->id);
        push_environment('env-linux', $this->otherServing);
        push_call($this->phone, 'POST', 'environments/env-linux/attention', push_attention_payload('env-linux'))
            ->assertStatus(202);
    });

    it('prunes attention events older than 7 days', function (): void {
        $old = ConnAttentionEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_environment_id' => $this->environment->id,
            'environment_id' => $this->environment->environment_id,
            'thread_id' => 'thread-old',
            'type' => 'attention',
            'kind' => 'approval',
            'occurred_at' => Carbon::now()->subDays(8),
        ]);
        DB::table('conn_attention_events')->where('id', $old->id)->update(['created_at' => Carbon::now()->subDays(8)]);

        $recent = ConnAttentionEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_environment_id' => $this->environment->id,
            'environment_id' => $this->environment->environment_id,
            'thread_id' => 'thread-recent',
            'type' => 'attention',
            'kind' => 'approval',
            'occurred_at' => Carbon::now()->subDays(2),
        ]);

        $this->artisan('conn:push:prune-events')->assertExitCode(0);

        expect(ConnAttentionEvent::query()->pluck('id')->all())->toBe([$recent->id]);
    });
});

describe('audience resolution and SendConnPushJob', function (): void {
    it('checks node access, profile binding, per-kind preferences and hide_titles at send time', function (): void {
        $profileNick = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
        $profileOther = ConnProfile::query()->create(['name' => 'Other', 'settings' => ['workspaces' => []], 'settings_version' => 1]);

        // Server Node and Nick's phone share profile Nick; otherPhone is bound to Other.
        ConnProfileBinding::query()->create(['node_id' => $this->serving->id, 'conn_profile_id' => $profileNick->id]);
        ConnProfileBinding::query()->create(['node_id' => $this->phone->id, 'conn_profile_id' => $profileNick->id]);
        ConnProfileBinding::query()->create(['node_id' => $this->otherPhone->id, 'conn_profile_id' => $profileOther->id]);

        $nickSecondPhone = push_node('iphone-nick-2', '10.44.0.25');
        $nickSecondPhone->accessibleNodes()->attach($this->serving->id);
        ConnProfileBinding::query()->create(['node_id' => $nickSecondPhone->id, 'conn_profile_id' => $profileNick->id]);

        $deviceVisible = ConnPushDevice::query()->create([
            'node_id' => $this->phone->id,
            ...push_device_payload([
                'apns_token' => str_repeat('11', 32),
                'preferences' => ['approval' => true, 'input' => true, 'turn_finished' => false, 'turn_failed' => true, 'hide_titles' => false],
            ]),
        ]);
        $deviceHidden = ConnPushDevice::query()->create([
            'node_id' => $nickSecondPhone->id,
            ...push_device_payload([
                'apns_token' => str_repeat('22', 32),
                'apns_environment' => 'production',
                'preferences' => ['approval' => true, 'input' => false, 'turn_finished' => true, 'turn_failed' => true, 'hide_titles' => true],
            ]),
        ]);
        ConnPushDevice::query()->create([
            'node_id' => $this->otherPhone->id,
            ...push_device_payload(['apns_token' => str_repeat('33', 32)]),
        ]);

        // 1. Approval event: both Nick phones match; otherPhone excluded by profile; hide_titles respected.
        $approval = ConnAttentionEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_environment_id' => $this->environment->id,
            'environment_id' => $this->environmentId,
            'thread_id' => 'thread-42',
            'type' => 'attention',
            'kind' => 'approval',
            'thread_title' => 'Deploy to prod',
            'occurred_at' => Carbon::now(),
        ]);

        app()->call([new SendConnPushJob($approval->id), 'handle']);

        expect($this->apns->sent)->toHaveCount(2);
        expect($this->apns->sent[0]->deviceRowId)->toBe($deviceVisible->id)
            ->and($this->apns->sent[0]->threadTitle)->toBe('Deploy to prod')
            ->and($this->apns->sent[0]->priority)->toBe(10)
            ->and($this->apns->sent[0]->pushType)->toBe('alert')
            ->and($this->apns->sent[0]->collapseId)->toBe(hash('sha256', "{$this->environmentId}:thread-42"));
        expect($this->apns->sent[1]->deviceRowId)->toBe($deviceHidden->id)
            ->and($this->apns->sent[1]->threadTitle)->toBeNull()
            ->and($this->apns->sent[1]->apnsEnvironment)->toBe('production');

        // 2. turn_finished event (after 30s thread cooldown): deviceVisible opted out; only deviceHidden gets priority 5 alert.
        $this->apns->reset();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $finished = ConnAttentionEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_environment_id' => $this->environment->id,
            'environment_id' => $this->environmentId,
            'thread_id' => 'thread-42',
            'type' => 'attention',
            'kind' => 'turn_finished',
            'thread_title' => 'Deploy to prod',
            'occurred_at' => Carbon::now(),
        ]);
        app()->call([new SendConnPushJob($finished->id), 'handle']);

        expect($this->apns->sent)->toHaveCount(1)
            ->and($this->apns->sent[0]->deviceRowId)->toBe($deviceHidden->id)
            ->and($this->apns->sent[0]->priority)->toBe(5);

        // 3. attention_cleared: background push, priority 5, null title, sent to both Nick devices even within 30s cooldown.
        $this->apns->reset();
        $cleared = ConnAttentionEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_environment_id' => $this->environment->id,
            'environment_id' => $this->environmentId,
            'thread_id' => 'thread-42',
            'type' => 'attention_cleared',
            'kind' => null,
            'thread_title' => null,
            'occurred_at' => Carbon::now(),
        ]);
        app()->call([new SendConnPushJob($cleared->id), 'handle']);

        expect($this->apns->sent)->toHaveCount(2)
            ->and($this->apns->sent[0]->pushType)->toBe('background')
            ->and($this->apns->sent[0]->priority)->toBe(5)
            ->and($this->apns->sent[0]->threadTitle)->toBeNull();

        // 4. Access revoked between queue and send time: phone loses access and gets no push.
        $this->apns->reset();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->phone->accessibleNodes()->detach($this->serving->id);
        app()->call([new SendConnPushJob($approval->id), 'handle']);

        expect($this->apns->sent)->toHaveCount(1)
            ->and($this->apns->sent[0]->deviceRowId)->toBe($deviceHidden->id);

        // 5. Flag turned off before job runs: sends nothing.
        $this->apns->reset();
        config(['orbit.conn.push' => false]);
        app()->call([new SendConnPushJob($approval->id), 'handle']);
        expect($this->apns->sent)->toBe([]);
        Carbon::setTestNow();
    });
});

describe('cleanup hooks', function (): void {
    it('removes device rows on Node removal', function (): void {
        ConnPushDevice::query()->create(['node_id' => $this->phone->id, ...push_device_payload()]);
        ConnPushDevice::query()->create(['node_id' => $this->otherPhone->id, ...push_device_payload()]);

        app(ConnNodeRemoval::class)->removing($this->phone);

        expect(ConnPushDevice::query()->where('node_id', $this->phone->id)->count())->toBe(0)
            ->and(ConnPushDevice::query()->where('node_id', $this->otherPhone->id)->count())->toBe(1);
    });

    it('removes device rows only when a Node loses its last access edge', function (): void {
        $this->phone->accessibleNodes()->attach($this->otherServing->id);
        ConnPushDevice::query()->create(['node_id' => $this->phone->id, ...push_device_payload()]);

        // Remove first edge: phone still has access to otherServing, so device stays.
        app(RemoveNodeAccessAction::class)->execute($this->phone, $this->serving, $this->gateway);
        expect(ConnPushDevice::query()->where('node_id', $this->phone->id)->count())->toBe(1);

        // Remove last edge: phone has no access left, so device is deleted.
        app(RemoveNodeAccessAction::class)->execute($this->phone, $this->otherServing, $this->gateway);
        expect(ConnPushDevice::query()->where('node_id', $this->phone->id)->count())->toBe(0);
    });

    it('removes device rows on pairing revocation only when no live pairing remains', function (): void {
        ConnPushDevice::query()->create(['node_id' => $this->phone->id, ...push_device_payload()]);
        $oldHeld = ConnPairing::query()->create([
            'node_id' => $this->phone->id,
            'conn_environment_id' => $this->environment->id,
            'pairing_link_id' => 'link-old-held',
            'client_label' => 'iphone-old',
            'held' => true,
            'session' => 'old-session',
            'session_expires_at' => Carbon::now()->addDays(5),
            'withdrawn_at' => Carbon::now(),
        ]);
        $liveHeld = ConnPairing::query()->create([
            'node_id' => $this->phone->id,
            'conn_environment_id' => $this->environment->id,
            'pairing_link_id' => 'link-live-held',
            'client_label' => 'iphone-new',
            'held' => true,
            'session' => 'new-session',
            'session_expires_at' => Carbon::now()->addDays(30),
        ]);

        // Routine held-session renewal revokes the old held pairing while the new one is live: device stays.
        $renewalRevoke = Operation::query()->create([
            'kind' => 'conn.pairing_revoke',
            'target_type' => 'conn_pairing_node',
            'target_id' => $this->phone->id,
            'node_id' => $this->gateway->id,
            'status' => OperationStatus::Running,
            'input' => [
                'conn_environment_id' => $this->environment->id,
                'pairing_ids' => [$oldHeld->id],
                'holding_pairing_ids' => [],
            ],
            'triggered_by' => 'operator',
        ]);
        app(ConnOperationCompletion::class)->complete($renewalRevoke, OperationOutcome::succeeded(['revoked_sessions' => 1]));
        expect(ConnPushDevice::query()->where('node_id', $this->phone->id)->count())->toBe(1);

        // Failed revoke of the last live pairing keeps device rows.
        $finalRevoke = Operation::query()->create([
            'kind' => 'conn.pairing_revoke',
            'target_type' => 'conn_pairing_node',
            'target_id' => $this->phone->id,
            'node_id' => $this->gateway->id,
            'status' => OperationStatus::Running,
            'input' => [
                'conn_environment_id' => $this->environment->id,
                'pairing_ids' => [$liveHeld->id],
                'holding_pairing_ids' => [],
            ],
            'triggered_by' => 'operator',
        ]);
        app(ConnOperationCompletion::class)->complete($finalRevoke, OperationOutcome::failed('conn.revocation_unmatched'));
        expect(ConnPushDevice::query()->where('node_id', $this->phone->id)->count())->toBe(1);

        // Succeeded revoke of the last live pairing removes device rows.
        app(ConnOperationCompletion::class)->complete($finalRevoke, OperationOutcome::succeeded(['revoked_sessions' => 1]));
        expect(ConnPushDevice::query()->where('node_id', $this->phone->id)->count())->toBe(0);
    });
});
