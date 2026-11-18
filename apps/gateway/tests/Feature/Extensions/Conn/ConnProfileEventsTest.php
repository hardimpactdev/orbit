<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Domain\Broadcasting\NamedBroadcast;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\ApplyConnProfileSectionsAction;
use Orbit\Extensions\Conn\Actions\BindConnNodeProfileAction;
use Orbit\Extensions\Conn\Domain\ConnAttachedThreads;
use Orbit\Extensions\Conn\Domain\ConnNodeAccessRemoval;
use Orbit\Extensions\Conn\Domain\ConnNodeRemoval;
use Orbit\Extensions\Conn\Domain\ConnProfileEvents;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

uses(LazilyRefreshDatabase::class);

function profile_event_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

/** @param array<string, mixed> $body */
function profile_event_call(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    $response = test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/'.$path, $body);
    // Each request runs in its own application in production; the test application keeps its terminating callbacks,
    // so without this a later request would send an earlier request's events again.
    new ReflectionProperty(app(), 'terminatingCallbacks')->setValue(app(), []);

    return $response;
}

function profile_event_auth(Node $caller, ?string $channel, string $path = 'conn/broadcasting/auth'): TestResponse
{
    return profile_event_call($caller, 'POST', $path, ['socket_id' => '123.456', 'channel_name' => $channel]);
}

function profile_event_realtime(bool $configured): void
{
    app()->instance(WebSocketCredentialManager::class, new readonly class($configured) implements WebSocketCredentialManager
    {
        public function __construct(private bool $configured) {}

        public function ensure(Node $node): never
        {
            throw new LogicException('Not used.');
        }

        public function current(): ?WebSocketCredentials
        {
            return $this->configured ? new WebSocketCredentials('app', 'reverb-key', 'reverb-secret', '10.44.0.5') : null;
        }

        public function purge(Node $node, bool $runtimeStopped = false): void {}
    });
    app()->forgetScopedInstances();
}

function profile_event_channel(Node $node): ?string
{
    return profile_event_call($node, 'GET', 'conn/me')->assertOk()->json('data.profile_channel');
}

function profile_event_key(Node $node): ?string
{
    return ConnProfileBinding::query()->where('node_id', $node->id)->value('event_key');
}

/** @return list<array{channel: string, event: string, payload: array<string, mixed>}> */
function profile_events_sent(): array
{
    return Event::dispatched(NamedBroadcast::class)->map(static fn (array $call): array => [
        'channel' => $call[0]->broadcastOn()[0]->name,
        'event' => $call[0]->broadcastAs(),
        'payload' => $call[0]->broadcastWith(),
    ])->values()->all();
}

function profile_event_patch(Node $caller, ConnProfile $profile, int $sectionVersion): TestResponse
{
    return profile_event_call($caller, 'PATCH', "conn/profiles/{$profile->id}/settings", [
        'sections' => ['appearance' => ['version' => $sectionVersion, 'value' => ['schema' => 1, 'mode' => 'dark', 'contrast' => 100]]],
    ]);
}

function profile_event_write(ConnProfile $profile): void
{
    app(ApplyConnProfileSectionsAction::class)->execute($profile, null, ['workspaces' => [['id' => 'w-'.bin2hex(random_bytes(3))]]]);
}

beforeEach(function (): void {
    app(ExtensionStore::class)->set('conn', true);
    profile_event_realtime(false);
    $this->beast = profile_event_node('beast', '10.44.0.7');
    $this->mini = profile_event_node('mini', '10.44.0.9');
    $this->phone = profile_event_node('phone', '10.44.0.77');
    $this->desktop = profile_event_node('desktop', '10.44.0.78');
    $this->phone->accessibleNodes()->attach($this->beast->id);
    $this->desktop->accessibleNodes()->attach($this->mini->id);
    $this->profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
    $this->other = ConnProfile::query()->create(['name' => 'Other', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
    profile_event_call($this->phone, 'PUT', 'conn/me/profile', ['profile_id' => $this->profile->id])->assertOk();
    profile_event_call($this->desktop, 'PUT', 'conn/me/profile', ['profile_id' => $this->profile->id])->assertOk();
});

describe('subscription', function (): void {
    beforeEach(fn () => profile_event_realtime(true));

    it('signs only the caller\'s own current channel for a Conn peer without Gateway authority', function (): void {
        $channel = profile_event_channel($this->phone);
        expect($channel)->toMatch('/^private-conn-profile\.[0-9a-f]{32}$/');

        $response = profile_event_auth($this->phone, $channel)->assertOk();

        expect($response->json())->toBe(['auth' => 'reverb-key:'.hash_hmac('sha256', "123.456:{$channel}", 'reverb-secret')]);
        profile_event_auth($this->phone, 'orbit', 'broadcasting/auth')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    });

    it('refuses every channel but the caller\'s current one', function (string $channel): void {
        profile_event_auth($this->phone, $channel)->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
    })->with([
        'another key' => fn (): string => 'private-conn-profile.'.str_repeat('a', 32),
        'another Node\'s channel' => fn (): string => (string) profile_event_channel($this->desktop),
        'the key without its prefix' => fn (): string => (string) profile_event_key($this->phone),
        'another prefix' => fn (): string => 'private-operation.'.profile_event_key($this->phone),
        'orbit' => fn (): string => 'orbit',
        'private-orbit' => fn (): string => 'private-orbit',
        'a presence channel' => fn (): string => 'presence-node.'.$this->phone->id,
        'a presence form of the key' => fn (): string => 'presence-conn-profile.'.profile_event_key($this->phone),
    ]);

    it('refuses the current channel with spaces around it, because only the exact name is signed', function (string $form): void {
        $channel = ' '.profile_event_channel($this->phone).' ';
        $response = $form === 'json'
            ? profile_event_auth($this->phone, $channel)
            : test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])->withHeader('Accept', 'application/json')
                ->post('/api/v1/conn/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

        $response->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
    })->with(['json', 'form']);

    it('refuses a socket id with a trailing line feed instead of signing it', function (string $form): void {
        $channel = profile_event_channel($this->phone);
        $body = ['socket_id' => "123.456\n", 'channel_name' => $channel];
        $response = $form === 'json'
            ? profile_event_call($this->phone, 'POST', 'conn/broadcasting/auth', $body)
            : test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])->withHeader('Accept', 'application/json')
                ->post('/api/v1/conn/broadcasting/auth', $body);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    })->with(['json', 'form']);

    it('refuses an ended key, a replaced key and a caller with no binding', function (): void {
        $channel = profile_event_channel($this->phone);

        Carbon::setTestNow(Carbon::now()->addMinutes(15)->addSecond());
        profile_event_auth($this->phone, $channel)->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
        $renewed = profile_event_channel($this->phone);
        expect($renewed)->not->toBe($channel);
        profile_event_auth($this->phone, $renewed)->assertOk();

        app(BindConnNodeProfileAction::class)->execute($this->phone, $this->profile);
        profile_event_auth($this->phone, $renewed)->assertForbidden();

        ConnProfileBinding::query()->where('node_id', $this->phone->id)->delete();
        profile_event_auth($this->phone, $renewed)->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
    });

    it('refuses a Node without collection access, a malformed socket and a switched-off extension', function (): void {
        $channel = profile_event_channel($this->phone);
        $taskVm = profile_event_node('beast-tvm-1', '10.44.0.120');

        profile_event_auth($taskVm, $channel)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        profile_event_call($this->phone, 'POST', 'conn/broadcasting/auth', ['socket_id' => 'x', 'channel_name' => $channel])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        $this->phone->accessibleNodes()->detach();
        profile_event_auth($this->phone, $channel)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        $this->phone->accessibleNodes()->attach($this->beast->id);

        app(ExtensionStore::class)->set('conn', false);
        profile_event_auth($this->phone, $channel)->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
        profile_event_call($this->phone, 'GET', 'conn/realtime')->assertStatus(409);
    });

    it('keeps every Conn profile channel out of the Gateway\'s own auth route', function (): void {
        $gateway = profile_event_node('gateway', '10.44.0.2');
        NodeRole::withoutEvents(fn () => $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
        $operator = profile_event_node('orbit-ops', '10.44.0.5');
        $operator->accessibleNodes()->attach($gateway->id);
        profile_event_call($operator, 'PUT', 'conn/me/profile', ['profile_id' => $this->profile->id])->assertOk();

        profile_event_auth($operator, profile_event_channel($operator), 'broadcasting/auth')
            ->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
        profile_event_auth($operator, profile_event_channel($this->phone), 'broadcasting/auth')
            ->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
        profile_event_auth($operator, 'orbit', 'conn/broadcasting/auth')->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
    });

    it('discovers the Reverb URL and key, and answers null and not configured without a Reverb connection', function (): void {
        profile_event_call($this->phone, 'GET', 'conn/realtime')->assertOk()
            ->assertJsonPath('data', ['url' => 'wss://reverb.orbit', 'key' => 'reverb-key']);

        profile_event_realtime(false);
        app()->forgetScopedInstances();
        profile_event_call($this->phone, 'GET', 'conn/realtime')->assertOk()->assertJsonPath('data', ['url' => null, 'key' => null]);
        profile_event_auth($this->phone, profile_event_channel($this->phone))->assertNotFound()->assertJsonPath('error.code', 'realtime.not_configured');
    });
});

describe('keys', function (): void {
    it('replaces the key on every bind, also A to B to A in the same second', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
        $keys = [profile_event_key($this->phone)];

        foreach ([$this->other, $this->profile, $this->profile] as $profile) {
            $channel = profile_event_call($this->phone, 'PUT', 'conn/me/profile', ['profile_id' => $profile->id])->assertOk()
                ->assertJsonPath('data.profile.id', $profile->id)->json('data.profile_channel');
            $keys[] = profile_event_key($this->phone);
            expect($channel)->toBe('private-conn-profile.'.end($keys));
        }

        expect(array_unique($keys))->toHaveCount(4);
    });

    it('renews a key older than 5 minutes on GET /me and keeps a younger one', function (): void {
        $first = profile_event_channel($this->phone);
        Carbon::setTestNow(Carbon::now()->addMinutes(4));
        expect(profile_event_channel($this->phone))->toBe($first);
        Carbon::setTestNow(Carbon::now()->addMinutes(1));
        $second = profile_event_channel($this->phone);
        expect($second)->not->toBe($first);

        ConnProfileBinding::query()->where('node_id', $this->phone->id)->delete();
        expect(profile_event_channel($this->phone))->toBeNull();
    });

    it('commits the key with the edge removal before a failing Metrics revoker, and rolls both back together', function (): void {
        $gateway = profile_event_node('gateway', '10.44.0.2');
        NodeRole::withoutEvents(fn () => $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
        $this->phone->accessibleNodes()->attach($gateway->id);
        $before = profile_event_key($this->phone);
        $failing = new class implements NodeAccessRemovalHook
        {
            public function removed(Node $consumer, Node $serving): void
            {
                throw new RuntimeException('a later hook fails');
            }
        };
        $remove = fn (array $hooks, MetricsAccessRevoker $metrics) => new RemoveNodeAccessAction(app(NodeAccessAuthorizer::class), $metrics, $hooks)
            ->execute($this->phone, $gateway, $this->phone);
        $metrics = new class implements MetricsAccessRevoker
        {
            public bool $fails = false;

            public int $calls = 0;

            public function revoke(): void
            {
                $this->calls++;

                if ($this->fails) {
                    throw new RuntimeException('Metrics is unreachable');
                }
            }
        };

        expect(fn () => $remove([app(ConnNodeAccessRemoval::class), $failing], $metrics))->toThrow(RuntimeException::class, 'a later hook fails');
        expect($metrics->calls)->toBe(1)
            ->and(profile_event_key($this->phone))->toBe($before)
            ->and(NodeAccess::query()->where('consumer_node_id', $this->phone->id)->where('serving_node_id', $gateway->id)->exists())->toBeTrue();

        $metrics->fails = true;
        expect(fn () => $remove([app(ConnNodeAccessRemoval::class)], $metrics))->toThrow(RuntimeException::class, 'Metrics is unreachable');
        expect(profile_event_key($this->phone))->not->toBe($before)
            ->and(NodeAccess::query()->where('consumer_node_id', $this->phone->id)->where('serving_node_id', $gateway->id)->exists())->toBeFalse();
    });

    it('reports the rollback, not a failing Metrics revoke, when the removal rolls back', function (): void {
        $gateway = profile_event_node('gateway', '10.44.0.2');
        NodeRole::withoutEvents(fn () => $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
        $this->phone->accessibleNodes()->attach($gateway->id);
        $before = profile_event_key($this->phone);
        $failing = new class implements NodeAccessRemovalHook
        {
            public function removed(Node $consumer, Node $serving): void
            {
                throw new RuntimeException('the key update fails');
            }
        };
        $metrics = new class implements MetricsAccessRevoker
        {
            public int $calls = 0;

            public function revoke(): void
            {
                $this->calls++;

                throw new RuntimeException('Caddy does not reload');
            }
        };
        Log::spy();

        expect(fn () => new RemoveNodeAccessAction(app(NodeAccessAuthorizer::class), $metrics, [app(ConnNodeAccessRemoval::class), $failing])
            ->execute($this->phone, $gateway, $this->phone))->toThrow(RuntimeException::class, 'the key update fails');

        expect($metrics->calls)->toBe(1)
            ->and(profile_event_key($this->phone))->toBe($before)
            ->and(NodeAccess::query()->where('consumer_node_id', $this->phone->id)->where('serving_node_id', $gateway->id)->exists())->toBeTrue();
        Log::shouldHaveReceived('warning')->with('node_access.metrics_revoke_failed', Mockery::on(
            fn (array $context): bool => $context['consumer_node_id'] === $this->phone->id && $context['exception'] === 'Caddy does not reload',
        ))->once();
    });

    it('still revokes Metrics access when a hook\'s after-commit work fails after the deletion committed', function (): void {
        $gateway = profile_event_node('gateway', '10.44.0.2');
        NodeRole::withoutEvents(fn () => $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
        $this->phone->accessibleNodes()->attach($gateway->id);
        $afterCommit = new class implements NodeAccessRemovalHook
        {
            public function removed(Node $consumer, Node $serving): void
            {
                DB::afterCommit(static fn () => throw new RuntimeException('the revoke admission fails'));
            }
        };
        $metrics = new class implements MetricsAccessRevoker
        {
            public int $calls = 0;

            public function revoke(): void
            {
                $this->calls++;
            }
        };

        expect(fn () => new RemoveNodeAccessAction(app(NodeAccessAuthorizer::class), $metrics, [app(ConnNodeAccessRemoval::class), $afterCommit])
            ->execute($this->phone, $gateway, $this->phone))->toThrow(RuntimeException::class, 'the revoke admission fails');

        expect($metrics->calls)->toBe(1)
            ->and(NodeAccess::query()->where('consumer_node_id', $this->phone->id)->where('serving_node_id', $gateway->id)->exists())->toBeFalse();
    });

    it('reads a current key on GET /me without starting a write transaction', function (): void {
        $transactions = 0;
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions++;
        });

        $binding = app(ConnProfileEvents::class)->renew($this->phone);
        expect($transactions)->toBe(0)->and($binding?->event_key)->toBe(profile_event_key($this->phone));

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $renewed = app(ConnProfileEvents::class)->renew($this->phone);
        expect($transactions)->toBe(1)->and($renewed?->event_key)->not->toBe($binding?->event_key);
    });

    it('replaces the key on node:access:remove even when access remains', function (): void {
        $this->phone->accessibleNodes()->attach($this->mini->id);
        $before = profile_event_key($this->phone);

        app(RemoveNodeAccessAction::class)->execute($this->phone, $this->mini, $this->phone);

        expect(profile_event_key($this->phone))->not->toBe($before);
    });

    it('replaces the key of every Node with an edge to a removed serving Node', function (): void {
        $phone = profile_event_key($this->phone);
        $desktop = profile_event_key($this->desktop);

        DB::transaction(fn () => app(ConnNodeRemoval::class)->removing($this->beast));

        expect(profile_event_key($this->phone))->not->toBe($phone)->and(profile_event_key($this->desktop))->toBe($desktop);
    });
});

describe('publication', function (): void {
    beforeEach(function (): void {
        Event::fake([NamedBroadcast::class]);
        // A write outside a request, as in a job or a console command, sends at once after its commit.
        request()->setRouteResolver(static fn (): null => null);
    });

    it('sends a PATCH\'s committed version, with exactly the profile id and version, once to each bound Node', function (): void {
        $phone = profile_event_channel($this->phone);
        $desktop = profile_event_channel($this->desktop);
        profile_event_call($this->desktop, 'PUT', 'conn/me/profile', ['profile_id' => $this->other->id])->assertOk();
        profile_event_call($this->desktop, 'PUT', 'conn/me/profile', ['profile_id' => $this->profile->id])->assertOk();
        $desktop = profile_event_channel($this->desktop);

        $version = profile_event_patch($this->phone, $this->profile, 0)->assertOk()->json('data.version');

        expect($version)->toBe(2)
            ->and(profile_event_call($this->phone, 'GET', "conn/profiles/{$this->profile->id}/settings")->json('data.version'))->toBe($version)
            ->and(profile_event_sent_sorted())->toBe(collect([
                ['channel' => $phone, 'event' => 'conn.profile.updated', 'payload' => ['profile_id' => $this->profile->id, 'version' => 2]],
                ['channel' => $desktop, 'event' => 'conn.profile.updated', 'payload' => ['profile_id' => $this->profile->id, 'version' => 2]],
            ])->sortBy('channel')->values()->all())
            ->and(json_encode(profile_events_sent()[0]['payload']))->toBe('{"profile_id":'.$this->profile->id.',"version":2}');
    });

    it('sends a PUT\'s committed version and nothing for a conflict or a refused body', function (): void {
        profile_event_call($this->phone, 'PUT', "conn/profiles/{$this->profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => []]])->assertOk();
        expect(collect(profile_events_sent())->pluck('payload.version')->unique()->all())->toBe([2])
            ->and(profile_events_sent())->toHaveCount(2);

        profile_event_call($this->phone, 'PUT', "conn/profiles/{$this->profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => []]])->assertStatus(409);
        profile_event_patch($this->phone, $this->profile, 7)->assertStatus(409);
        profile_event_call($this->phone, 'PUT', "conn/profiles/{$this->profile->id}/settings", ['version' => 2, 'settings' => ['attachedThreads' => []]])->assertUnprocessable();

        expect(profile_events_sent())->toHaveCount(2);
    });

    it('sends only after the outermost transaction commits, and nothing for a rolled-back write', function (): void {
        DB::transaction(function (): void {
            DB::transaction(fn () => profile_event_write($this->profile));
            expect(profile_events_sent())->toBe([]);
        });
        expect(profile_events_sent())->toHaveCount(2)->and(profile_events_sent()[0]['payload']['version'])->toBe(2);

        try {
            DB::transaction(function (): void {
                profile_event_write($this->profile);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        DB::transaction(function (): void {
            try {
                DB::transaction(function (): void {
                    profile_event_write($this->profile);
                    throw new RuntimeException('roll back the inner write');
                });
            } catch (RuntimeException) {
            }
        });

        expect(profile_events_sent())->toHaveCount(2)->and($this->profile->fresh()->settings_version)->toBe(2);
    });

    it('sends nothing to a key replaced before the send, A then B then A', function (): void {
        DB::transaction(function (): void {
            profile_event_write($this->profile);
            app(BindConnNodeProfileAction::class)->execute($this->phone, $this->other);
            app(BindConnNodeProfileAction::class)->execute($this->phone, $this->profile);
        });

        expect(collect(profile_events_sent())->pluck('channel')->all())->toBe([profile_event_channel($this->desktop)]);
    });

    it('sends nothing to an ended key, nor while the extension is off', function (): void {
        Carbon::setTestNow(Carbon::now()->addMinutes(16));
        profile_event_write($this->profile);
        expect(profile_events_sent())->toBe([]);

        profile_event_channel($this->phone);
        app(ExtensionStore::class)->set('conn', false);
        profile_event_write($this->profile);
        expect(profile_events_sent())->toBe([]);
    });

    it('replaces the key of a Node it finds inactive or without collection access, and sends it nothing', function (): void {
        $phone = profile_event_key($this->phone);
        $desktop = profile_event_key($this->desktop);
        Node::withoutEvents(fn () => $this->phone->forceFill(['status' => 'removing'])->save());
        $this->desktop->accessibleNodes()->detach();

        profile_event_write($this->profile);

        expect(profile_events_sent())->toBe([])
            ->and(profile_event_key($this->phone))->not->toBe($phone)
            ->and(profile_event_key($this->desktop))->not->toBe($desktop);
    });

    it('sends the launch attachment write and the drop of a destroyed Instance\'s threads once each', function (): void {
        $server = ConnEnvironment::query()->create([
            'environment_id' => 'env-gone', 'label' => 'env-gone', 'url' => 'http://10.44.0.30:3773', 'node_id' => $this->beast->id,
            'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30), 'registered_at' => Carbon::now(),
        ]);
        app(ApplyConnProfileSectionsAction::class)->execute($this->profile, null,
            ['attachedThreads' => ['schema' => 1, 'threads' => [['environmentId' => 'env-gone', 'threadId' => 't-1']]]], launchEnvironment: $server);
        expect(collect(profile_events_sent())->pluck('payload.version')->all())->toBe([2, 2]);

        DB::transaction(fn () => app(ConnAttachedThreads::class)->drop(['env-gone']));

        expect($this->profile->fresh()->settings_version)->toBe(3)
            ->and(collect(profile_events_sent())->pluck('payload.version')->all())->toBe([2, 2, 3, 3]);
    });

    it('stops the sends that have not started when the extension is switched off during a delivery', function (): void {
        Event::swap(Event::getFacadeRoot()->dispatcher);
        $sent = [];
        Event::listen(NamedBroadcast::class, static function (NamedBroadcast $event) use (&$sent): void {
            $sent[] = $event->channel;
            app(ExtensionStore::class)->set('conn', false);
        });

        profile_event_write($this->profile);

        expect($sent)->toHaveCount(1);
    });

    it('treats a Node deleted during the final check as ineligible and still notifies the next recipient', function (): void {
        // The write's audience capture reads both bindings; the final check then reads the phone's binding first.
        $reads = 0;
        $phone = $this->phone->id;
        ConnProfileBinding::retrieved(static function (ConnProfileBinding $binding) use (&$reads, $phone): void {
            if (++$reads === 3 && $binding->node_id === $phone) {
                Node::query()->whereKey($phone)->delete();
            }
        });

        profile_event_write($this->profile);

        expect($this->profile->fresh()->settings_version)->toBe(2)
            ->and(Node::query()->find($phone))->toBeNull()
            ->and(profile_events_sent())->toBe([['channel' => 'private-conn-profile.'.profile_event_key($this->desktop), 'event' => 'conn.profile.updated',
                'payload' => ['profile_id' => $this->profile->id, 'version' => 2]]]);
    });

    it('logs a failed broadcast and keeps the accepted write', function (): void {
        Event::swap(Event::getFacadeRoot()->dispatcher);
        Event::listen(NamedBroadcast::class, static fn () => throw new RuntimeException('Reverb is down'));
        Log::spy();

        profile_event_patch($this->phone, $this->profile, 0)->assertOk()->assertJsonPath('data.version', 2);

        expect(profile_event_call($this->phone, 'GET', "conn/profiles/{$this->profile->id}/settings")->json('data.version'))->toBe(2);
        Log::shouldHaveReceived('warning')->with('Failed to broadcast a record event.', Mockery::on(
            fn (array $context): bool => $context['type'] === 'conn.profile.updated' && $context['id'] === $this->profile->id && $context['exception'] === 'Reverb is down',
        ))->twice();
    });
});

/** @return list<array{channel: string, event: string, payload: array<string, mixed>}> */
function profile_event_sent_sorted(): array
{
    return collect(profile_events_sent())->sortBy('channel')->values()->all();
}
