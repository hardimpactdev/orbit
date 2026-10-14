<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Activity;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

describe('PATCH /api/v1/nodes/{node}/wireguard-key', function (): void {
    beforeEach(function (): void {
        $this->travelTo(new DateTimeImmutable('2026-08-25T12:00:00Z'));
        $this->orbitHome = sys_get_temp_dir().'/orbit-wireguard-key-'.Str::uuid();
        mkdir($this->orbitHome.'/wireguard', 0o700, true);
        file_put_contents($this->orbitHome.'/wireguard/private.key', base64_encode(str_repeat('s', 32)));
        file_put_contents($this->orbitHome.'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
        $this->operator = $this->markAsGateway(Node::query()->create([
            'name' => 'operator', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => base64_encode(str_repeat('h', 32)),
        ]));
        $this->operator->roles()->create(['role' => RoleName::Vpn]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->node = Node::query()->create([
            'name' => 'laptop', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
            'wireguard_public_key' => base64_encode(str_repeat('o', 32)),
        ]);
        $this->other = Node::query()->create([
            'name' => 'other-peer', 'status' => LifecycleStatus::Failed,
            'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3',
            'wireguard_public_key' => base64_encode(str_repeat('p', 32)),
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure('10.44.0.0/24');
        $this->configuration = new VpnConfigurationRepository($settings, $this->orbitHome);
        app()->instance(VpnConfigurationRepository::class, $this->configuration);
        $this->processes = new class($this->orbitHome) implements ProcessRunner
        {
            public int $calls = 0;

            public bool $fail = false;

            public string $live = '';

            public function __construct(private readonly string $home) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->calls++;

                if ($this->fail) {
                    return new CommandResult(1, 'private remote output', 'private remote error', 1, false);
                }

                if ($invocation->arguments === ['sudo', 'mv', '-f', '--', '/etc/wireguard/orbit-candidate.conf', '/etc/wireguard/orbit.conf']) {
                    $this->live = (string) file_get_contents($this->home.'/generated/wireguard/orbit.conf');
                }

                return new CommandResult(0, '', '', 1, false);
            }
        };
        $this->projection = new NativeGatewayPeerProjectionManager(
            $this->configuration, new WireGuardServerConfigRenderer, new ProtectedFileWriter,
            $this->processes, $this->orbitHome,
        );
        app()->instance(GatewayPeerProjectionManager::class, $this->projection);
        $this->projection->converge($this->node);
        $this->beforeConfig = $this->processes->live;
        $this->beforeCalls = $this->processes->calls;
        $this->oldKey = $this->node->wireguard_public_key;
        $this->newKey = base64_encode(str_repeat('n', 32));
        $this->endpoint = '/api/v1/nodes/'.$this->node->id.'/wireguard-key';
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->orbitHome);
    });

    it('stores only the key, broadcasts the update, records safe Activity and changes only that hub peer', function (): void {
        $requestId = (string) Str::uuid();
        $before = $this->node->getAttributes();
        Event::fake([RecordBroadcast::class]);
        $response = $this->withHeader('X-Orbit-Request-Id', $requestId)
            ->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])
            ->assertOk()->assertJsonPath('data.wireguard_public_key', $this->newKey)
            ->assertJsonPath('meta.request_id', $requestId);
        record_fixture($response, 'nodes/node-wireguard-key/updated', SetNodeWireGuardKeyRequest::class, 'PATCH /api/v1/nodes/{node}/wireguard-key');

        expect($this->node->refresh()->wireguard_public_key)->toBe($this->newKey)
            ->and($this->processes->live)->toBe(str_replace($this->oldKey, $this->newKey, $this->beforeConfig))
            ->and(array_diff_assoc($this->node->getAttributes(), $before))->toMatchArray(['wireguard_public_key' => $this->newKey]);
        foreach (['wireguard_ip', 'ssh_host_fingerprint', 'status', 'wireguard_endpoint_override', 'dns_server_override'] as $field) {
            expect($this->node->getRawOriginal($field))->toBe($before[$field] ?? null);
        }
        Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated
            && $event->id === $this->node->id && $event->data['wireguard_public_key'] === $this->newKey);
        $activity = Activity::query()->where('request_id', $requestId)->sole();
        expect($activity)->command->toBe('node:wireguard-key')->status->toBe('succeeded')
            ->target_node_id->toBe($this->node->id)->caller_node_id->toBe($this->operator->id)
            ->and($activity->properties?->get('input'))->toBe([]);
    });

    it('projects even when the key is unchanged', function (): void {
        $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->oldKey])->assertOk();
        expect($this->processes->calls)->toBeGreaterThan($this->beforeCalls)
            ->and($this->processes->live)->toBe($this->beforeConfig);
    });

    it('rejects invalid bodies before writes or projection', function (string $body): void {
        $this->call('PATCH', $this->endpoint, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->calls)->toBe($this->beforeCalls)->and($this->processes->live)->toBe($this->beforeConfig);
        $activity = Activity::query()->where('command', 'node:wireguard-key')->sole();
        expect($activity->properties?->get('input'))->toBe([])
            ->and($activity->toJson())->not->toContain('do not record');
    })->with([
        'missing' => '{}', 'null' => '{"wireguard_public_key":null}', 'empty' => '{"wireguard_public_key":""}',
        'number' => '{"wireguard_public_key":42}', 'array' => '{"wireguard_public_key":[]}',
        'malformed' => '{"wireguard_public_key":"invalid"}',
        'short' => json_encode(['wireguard_public_key' => base64_encode(str_repeat('a', 31))], JSON_THROW_ON_ERROR),
        'long' => json_encode(['wireguard_public_key' => base64_encode(str_repeat('a', 33))], JSON_THROW_ON_ERROR),
        'whitespace' => json_encode(['wireguard_public_key' => ' '.base64_encode(str_repeat('a', 32))], JSON_THROW_ON_ERROR),
        'noncanonical' => '{"wireguard_public_key":"'.str_repeat('A', 42).'B="}',
        'unknown' => '{"wireguard_public_key":"'.base64_encode(str_repeat('a', 32)).'","private_key":"do not record"}',
        'duplicate' => '{"wireguard_public_key":"bad","wireguard_public_key":"'.base64_encode(str_repeat('a', 32)).'"}',
        'escaped duplicate' => '{"wireguard_public_key":"bad","wireguard_public_\\u006bey":"'.base64_encode(str_repeat('a', 32)).'"}',
        'not object' => '[]', 'broken json' => '{',
    ]);

    it('refuses managed nodes in every status and on both platforms', function (string $platform, string $status): void {
        $this->node->update(['platform' => $platform, 'status' => $status, 'ssh_host_fingerprint' => 'SHA256:pinned']);
        $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])
            ->assertConflict()->assertJsonPath('error.code', 'node.wireguard_key_managed');
        expect($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->calls)->toBe($this->beforeCalls);
        expect(Activity::query()->where('command', 'node:wireguard-key')->sole()->status)->toBe('failed');
    })->with(['linux', 'macos'])->with(['active', 'failed', 'provisioning', 'removing']);

    it('checks gateway assignments first even if inactive and managed', function (): void {
        $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Failed]);
        $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
        $response = $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])
            ->assertConflict()->assertJsonPath('error.code', 'node.wireguard_key_gateway');
        record_fixture($response, 'nodes/node-wireguard-key/gateway-refused', SetNodeWireGuardKeyRequest::class, 'PATCH /api/v1/nodes/{node}/wireguard-key');
        expect($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->calls)->toBe($this->beforeCalls);
    });

    it('requires an address and respects the lifecycle lock', function (): void {
        new NativeNodeProvisioningLock()->run($this->node->name, function (): void {
            $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])
                ->assertConflict()->assertJsonPath('error.code', 'node.provisioning_busy');
        });
        $this->node->update(['wireguard_ip' => null]);
        $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])
            ->assertUnprocessable()->assertJsonPath('error.code', 'node.wireguard_ip_required');
        expect($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->calls)->toBe($this->beforeCalls);
    });

    it('refuses collisions with all other nodes or the configured hub without disclosing identity', function (string $source): void {
        $key = $source === 'hub' ? base64_encode(str_repeat('h', 32)) : $this->other->wireguard_public_key;
        $response = $this->patchJson($this->endpoint, ['wireguard_public_key' => $key])
            ->assertConflict()->assertJsonPath('error.code', 'node.wireguard_key_conflict');
        expect($response->getContent())->not->toContain('other-peer')
            ->and($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->calls)->toBe($this->beforeCalls)->and($this->processes->live)->toBe($this->beforeConfig);
    })->with(['node', 'hub']);

    it('retains the stored key on projection failure and repairs the hub on identical replay', function (): void {
        $this->processes->fail = true;
        $response = $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])
            ->assertUnprocessable()->assertJsonPath('error.code', 'vpn.server_config_install_failed')
            ->assertJsonPath('error.details.step', 'wireguard-server-install');
        expect($response->getContent())->not->toContain('private remote')
            ->and($this->node->refresh()->wireguard_public_key)->toBe($this->newKey)
            ->and($this->processes->live)->toBe($this->beforeConfig);
        $activity = Activity::query()->where('command', 'node:wireguard-key')->sole();
        expect($activity->status)->toBe('failed')
            ->and($activity->toJson())->not->toContain('private remote', base64_encode(str_repeat('s', 32)));
        record_fixture($response, 'nodes/node-wireguard-key/projection-failed', SetNodeWireGuardKeyRequest::class, 'PATCH /api/v1/nodes/{node}/wireguard-key');
        $this->processes->fail = false;
        $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])->assertOk();
        expect($this->processes->live)->toBe(str_replace($this->oldKey, $this->newKey, $this->beforeConfig));
    });

    it('refuses a collision with a peer the caller cannot access without disclosing it', function (): void {
        $caller = Node::query()->create(['name' => 'limited-caller', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.4', 'wireguard_ip' => '10.44.0.4']);
        $caller->accessibleNodes()->attach($this->node->id);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
        $response = $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->other->wireguard_public_key])
            ->assertConflict()->assertJsonPath('error.code', 'node.wireguard_key_conflict');
        expect($response->getContent())->not->toContain($this->other->name)
            ->and($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->live)->toBe($this->beforeConfig)->and($this->processes->calls)->toBe($this->beforeCalls);
    });

    it('checks collisions before restoring a reprovisioning snapshot and holds the guard through its save', function (): void {
        $this->configuration->assignPeerKey($this->node, $this->newKey);
        $this->configuration->assignPeerKey($this->other, $this->oldKey);
        $saved = false;
        expect(fn () => $this->configuration->assignPeerKey($this->node, $this->oldKey, function () use (&$saved): void {
            $saved = true;
            DB::transaction(fn () => $this->node->update(['wireguard_public_key' => $this->oldKey, 'status' => LifecycleStatus::Failed]));
        }))->toThrow(ResourceOperationException::class);
        expect($saved)->toBeFalse()->and($this->node->refresh()->wireguard_public_key)->toBe($this->newKey)
            ->and($this->node->status)->toBe(LifecycleStatus::Active);
        $this->configuration->assignPeerKey($this->other, base64_encode(str_repeat('q', 32)));
        $this->configuration->assignPeerKey($this->node, $this->oldKey, function (): void {
            DB::transaction(fn () => $this->node->update(['wireguard_public_key' => $this->oldKey, 'status' => LifecycleStatus::Failed]));
        });
        expect($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)->and($this->node->status)->toBe(LifecycleStatus::Failed);
    });

    it('serializes simultaneous key assignments to different nodes', function (): void {
        $database = wireguard_key_probe_database($this->orbitHome);
        $first = wireguard_key_probe($this->orbitHome, $database, 'assign', $this->node->id, $this->newKey);
        $second = wireguard_key_probe($this->orbitHome, $database, 'assign', $this->other->id, $this->newKey);

        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 10;

            while (! is_file($this->orbitHome.'/ready-'.$this->node->id) || ! is_file($this->orbitHome.'/ready-'.$this->other->id)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Key assignment probes did not reach the barrier: '.$first->getOutput().$first->getErrorOutput().$second->getOutput().$second->getErrorOutput());
                }

                usleep(1_000);
            }

            file_put_contents($this->orbitHome.'/assign-go', 'go');
            $first->wait();
            $second->wait();
            expect([$first->getExitCode(), $second->getExitCode()])->toEqualCanonicalizing([0, 1], $first->getOutput().$first->getErrorOutput().$second->getOutput().$second->getErrorOutput())
                ->and([$first->getOutput(), $second->getOutput()])->toEqualCanonicalizing(['assigned', 'node.wireguard_key_conflict']);
            $pdo = new PDO('sqlite:'.$database);
            $query = $pdo->prepare('SELECT count(*) FROM nodes WHERE wireguard_public_key = ?');
            $query->execute([$this->newKey]);
            expect((int) $query->fetchColumn())->toBe(1);
        } finally {
            $first->stop();
            $second->stop();
            unlink($database);
        }
    });

    it('leaves the documented state after an interrupted publication and repairs it by authorized identical replay', function (string $window, int $exit): void {
        $database = wireguard_key_probe_database($this->orbitHome);
        file_put_contents($this->orbitHome.'/live.conf', $this->beforeConfig);

        try {
            $crash = wireguard_key_probe($this->orbitHome, $database, $window, $this->node->id, $this->newKey);
            $crash->run();
            expect($crash->getExitCode())->toBe($exit, $crash->getErrorOutput().$crash->getOutput());
            $pdo = new PDO('sqlite:'.$database);
            expect($pdo->query('SELECT wireguard_public_key FROM nodes WHERE id = '.$this->node->id)->fetchColumn())->toBe($this->newKey)
                ->and(file_get_contents($this->orbitHome.'/live.conf'))->toBe($window === 'before-publication'
                    ? $this->beforeConfig : str_replace($this->oldKey, $this->newKey, $this->beforeConfig));
            $activity = $pdo->query("SELECT command, status, error_code FROM activity_log WHERE command = 'node:wireguard-key'")->fetch(PDO::FETCH_ASSOC);
            expect($activity)->toMatchArray(['command' => 'node:wireguard-key', 'status' => 'failed', 'error_code' => 'activity.interrupted']);
            $replay = wireguard_key_probe($this->orbitHome, $database, 'complete', $this->node->id, $this->newKey);
            $replay->run();
            expect($replay->getExitCode())->toBe(0, $replay->getErrorOutput().$replay->getOutput())
                ->and(file_get_contents($this->orbitHome.'/live.conf'))->toBe(str_replace($this->oldKey, $this->newKey, $this->beforeConfig))
                ->and($pdo->query("SELECT count(*) FROM activity_log WHERE command = 'node:wireguard-key' AND status = 'succeeded'")->fetchColumn())->toBe(1);
        } finally {
            unlink($database);
        }
    })->with([['before-publication', 86], ['after-publication', 87]]);

    it('enforces target access, active peer identity, numeric paths and unknown IDs', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
        $this->patchJson('/api/v1/nodes/'.$this->other->id.'/wireguard-key', ['wireguard_public_key' => $this->newKey])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99']);
        $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->newKey])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->patchJson('/api/v1/nodes/999999/wireguard-key', ['wireguard_public_key' => $this->newKey])->assertNotFound();
        $this->patchJson('/api/v1/nodes/laptop/wireguard-key', ['wireguard_public_key' => $this->newKey])->assertNotFound();
        expect($this->node->refresh()->wireguard_public_key)->toBe($this->oldKey)
            ->and($this->processes->calls)->toBe($this->beforeCalls);
    });
});

/** Copy the isolated test schema and rows into a separate database for real competing PHP requests. */
function wireguard_key_probe_database(string $home): string
{
    $path = sys_get_temp_dir().'/orbit-gateway-test-wireguard-'.basename($home).'.sqlite';
    $target = new PDO('sqlite:'.$path);
    // Match the Gateway before competing probes connect; switching journal mode can lock SQLite.
    $target->exec('PRAGMA journal_mode=WAL');
    $source = DB::connection()->getPdo();
    $tables = $source->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tables as $table) {
        $target->exec($table['sql']);
        $name = '"'.str_replace('"', '""', $table['name']).'"';
        $rows = $source->query('SELECT * FROM '.$name)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $columns = implode(', ', array_map(static fn (string $column): string => '"'.str_replace('"', '""', $column).'"', array_keys($row)));
            $insert = $target->prepare('INSERT INTO '.$name.' ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
            $insert->execute(array_values($row));
        }
    }

    return $path;
}

function wireguard_key_probe(string $home, string $database, string $mode, int $nodeId, string $key): Process
{
    return new Process([
        PHP_BINARY, base_path('tests/Fixtures/Nodes/WireguardKeyRequestProbe.php'), $home, $mode, (string) $nodeId, $key,
    ], base_path(), [
        'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => false,
        'ORBIT_TEST_DATABASE' => $database, 'ORBIT_HOME' => $home,
        'CACHE_STORE' => 'array', 'BROADCAST_CONNECTION' => 'null',
    ], timeout: 20);
}
