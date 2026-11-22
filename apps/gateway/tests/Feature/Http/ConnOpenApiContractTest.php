<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\Managed\EnrolConnManagedTargetAction;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedNoticePublisher;
use Orbit\Extensions\Conn\Domain\T3AdminSession;
use Orbit\Extensions\Conn\Domain\T3EnvironmentDescriptor;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnReservation;

uses(LazilyRefreshDatabase::class);

/**
 * Decode JSON preserving stdClass vs array so empty `{}` and `[]` stay distinct during schema validation.
 */
function openapi_decode_json(string $json): mixed
{
    return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function openapi_document(): array
{
    static $spec = null;
    if ($spec === null) {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    return $spec;
}

/**
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function openapi_resolve(array $schema, array $spec): array
{
    while (isset($schema['$ref']) && is_string($schema['$ref'])) {
        $node = $spec;
        foreach (explode('/', ltrim($schema['$ref'], '#/')) as $part) {
            $node = $node[$part];
        }
        $schema = $node;
    }

    return $schema;
}

/**
 * Strict OpenAPI 3.1 / JSON Schema validator over stdClass-decoded JSON values.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $spec
 * @return list<string>
 */
function openapi_problems(mixed $value, array $schema, array $spec, string $path = 'body'): array
{
    $schema = openapi_resolve($schema, $spec);

    if (isset($schema['allOf']) && is_array($schema['allOf'])) {
        $errors = [];
        foreach ($schema['allOf'] as $branch) {
            $errors = [...$errors, ...openapi_problems($value, $branch, $spec, $path)];
        }

        return $errors;
    }

    if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
        $attempts = array_map(static fn (array $opt): array => openapi_problems($value, $opt, $spec, $path), $schema['oneOf']);
        $matches = count(array_filter($attempts, static fn (array $e): bool => $e === []));
        if ($matches === 1) {
            return [];
        }
        if ($matches > 1) {
            return ["{$path} matched {$matches} oneOf alternatives, expected 1"];
        }
        usort($attempts, static fn (array $a, array $b): int => count($a) <=> count($b));

        return ["{$path} matched no oneOf alternative; closest: ".implode('; ', $attempts[0])];
    }

    if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
        $attempts = array_map(static fn (array $opt): array => openapi_problems($value, $opt, $spec, $path), $schema['anyOf']);
        foreach ($attempts as $attempt) {
            if ($attempt === []) {
                return [];
            }
        }
        usort($attempts, static fn (array $a, array $b): int => count($a) <=> count($b));

        return ["{$path} matched no anyOf alternative; closest: ".implode('; ', $attempts[0])];
    }

    $errors = [];
    if (isset($schema['type'])) {
        $allowed = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
        $actual = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            $value instanceof stdClass => 'object',
            default => get_debug_type($value),
        };
        $ok = in_array($actual, $allowed, true) || ($actual === 'integer' && in_array('number', $allowed, true));
        if (! $ok) {
            return ["{$path} is {$actual}, expected ".implode('|', $allowed)];
        }
    }

    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        $errors[] = "{$path} is ".json_encode($value).', expected const '.json_encode($schema['const']);
    }

    if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path} is ".json_encode($value).', expected one of '.json_encode($schema['enum']);
    }

    if (is_string($value)) {
        if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            $errors[] = "{$path} shorter than minLength {$schema['minLength']}";
        }
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = "{$path} longer than maxLength {$schema['maxLength']}";
        }
    }

    if (is_int($value) || is_float($value)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$path} is {$value}, below minimum {$schema['minimum']}";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$path} is {$value}, above maximum {$schema['maximum']}";
        }
    }

    if (is_array($value)) {
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            $errors[] = "{$path} has ".count($value)." items, maxItems is {$schema['maxItems']}";
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $idx => $item) {
                $errors = [...$errors, ...openapi_problems($item, $schema['items'], $spec, "{$path}[{$idx}]")];
            }
        }
    }

    if ($value instanceof stdClass) {
        $vars = get_object_vars($value);
        foreach ($schema['required'] ?? [] as $req) {
            if (! array_key_exists($req, $vars)) {
                $errors[] = "{$path}.{$req} is required";
            }
        }
        $props = $schema['properties'] ?? [];
        foreach ($props as $key => $propSchema) {
            if (array_key_exists($key, $vars)) {
                $errors = [...$errors, ...openapi_problems($vars[$key], $propSchema, $spec, "{$path}.{$key}")];
            }
        }
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
            foreach (array_keys($vars) as $key) {
                if (! array_key_exists($key, $props)) {
                    $errors[] = "{$path}.{$key} is not allowed (additionalProperties: false)";
                }
            }
        }
    }

    return $errors;
}

/**
 * Validate a real HTTP TestResponse against the operation's documented response schema in docs/openapi.json.
 *
 * @param  array<string, bool>  $covered
 */
function assert_response_matches_openapi(TestResponse $response, string $method, string $pathTemplate, int $expectedStatus, array &$covered): void
{
    $exc = $response->exception ? ' | EXC: '.$response->exception::class.': '.$response->exception->getMessage().' @ '.$response->exception->getFile().':'.$response->exception->getLine() : '';
    expect($response->getStatusCode())->toBe($expectedStatus, "{$method} {$pathTemplate} returned {$response->getStatusCode()}: ".$response->getContent().$exc);
    $spec = openapi_document();
    $op = $spec['paths'][$pathTemplate][strtolower($method)] ?? null;
    expect($op)->not->toBeNull("Missing {$method} {$pathTemplate} in docs/openapi.json");
    $opId = (string) $op['operationId'];
    $covered[$opId] = true;

    $respSpec = $op['responses'][(string) $expectedStatus] ?? null;
    expect($respSpec)->not->toBeNull("{$opId} ({$method} {$pathTemplate}) has no {$expectedStatus} response in docs/openapi.json");

    if ($expectedStatus === 204) {
        expect($response->getContent())->toBe('');

        return;
    }

    $schema = $respSpec['content']['application/json']['schema'] ?? null;
    expect($schema)->not->toBeNull("{$opId} {$expectedStatus} response has no application/json schema");

    $decoded = openapi_decode_json((string) $response->getContent());
    $problems = openapi_problems($decoded, $schema, $spec);
    expect($problems)->toBe([], "{$opId} ({$method} {$pathTemplate} {$expectedStatus}) schema errors:\n".implode("\n", $problems));
}

function openapi_node(string $name, string $ip, bool $gateway = false, ?string $bridge = null): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->forceCreate([
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
        'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:'.$name,
        'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => $name.'-host-key',
    ]));
    if ($bridge !== null) {
        $node->forceFill(['instance_bridge' => $bridge])->saveQuietly();
    }
    if ($gateway) {
        NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::Gateway->value, 'status' => 'active']);
        NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::WebSocket->value, 'status' => 'active']);
        NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::Swamp->value, 'status' => 'active']);
    }

    return $node;
}

/** @param array<string, mixed> $body
 * @param  array<string, string>  $headers
 */
function openapi_http(Node $caller, string $method, string $uri, array $body = [], array $headers = []): TestResponse
{
    $server = ['REMOTE_ADDR' => $caller->wireguard_ip, 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $key => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
    }
    if ($body !== []) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $content = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $response = test()->call($method, $uri, [], [], [], $server, $content);
    new ReflectionProperty(app(), 'terminatingCallbacks')->setValue(app(), []);

    return $response;
}

function openapi_configure_realtime(): void
{
    app()->instance(WebSocketCredentialManager::class, new readonly class implements WebSocketCredentialManager
    {
        public function ensure(Node $node): never
        {
            throw new LogicException('Not called in read tests.');
        }

        public function current(): WebSocketCredentials
        {
            return new WebSocketCredentials('app', 'reverb-key', 'reverb-secret', '10.44.0.1');
        }

        public function purge(Node $node, bool $runtimeStopped = false): void {}
    });
    app()->forgetScopedInstances();
}

describe('Conn OpenAPI contract', function (): void {
    it('validates real HTTP kernel responses of every outcome-3 operation against docs/openapi.json', function (): void {
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'orbit.conn.managed_targets' => true,
            'orbit.conn.thread_index_poll' => false,
            'swamp.address' => 'swamp.test',
            'swamp.ca_path' => '/tmp/fixture-ca',
            'swamp.run_token' => 'fixture-token',
        ]);
        app(ExtensionStore::class)->set('conn', true);
        app(ExtensionStore::class)->set('tasks', true);
        openapi_configure_realtime();

        $gateway = openapi_node('gw', '10.44.0.1', gateway: true);
        $host = openapi_node('beast', '10.44.0.10', bridge: '10.253.0.0/24');
        NodeRole::query()->create(['node_id' => $host->id, 'role' => RoleName::AppDev->value, 'status' => 'active']);
        $caller = openapi_node('phone', '10.44.0.20');
        $caller->accessibleNodes()->attach([$gateway->id, $host->id]);
        $host->accessibleNodes()->attach($gateway->id);

        // Fake external T3, Swamp WebSocket vault, ContainerModelRoute, ProcessRunner, SSH, and Managed Notice Publisher stand-ins
        app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
        {
            public function assertReady(): void {}

            public function origin(): string
            {
                return 'http://10.44.0.3:8318';
            }

            public function key(): string
            {
                return 'placeholder-model-key';
            }
        });
        $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
        app()->instance(WebSocketClient::class, new class implements WebSocketClient
        {
            /** @var array<string, mixed> */
            private array $last = [];

            public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

            public function send(array $message): void
            {
                $this->last = $message;
            }

            public function receive(float $timeoutSeconds): array
            {
                return match ($this->last['type'] ?? null) {
                    'vault.put' => [['id' => $this->last['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                        'vaultName' => $this->last['payload']['vaultName'], 'secretKey' => $this->last['payload']['key'], 'vaultType' => 'local_encryption']]]],
                    default => [['id' => $this->last['id'] ?? '1', 'type' => 'vault.delete', 'payload' => ['data' => []]]],
                };
            }

            public function close(): void {}

            public function isConnected(): bool
            {
                return true;
            }
        });
        app()->instance(ConnManagedNoticePublisher::class, new class implements ConnManagedNoticePublisher
        {
            public function publish(string $channel, array $data): bool
            {
                return true;
            }
        });
        app()->instance(T3ServerClient::class, new class implements T3ServerClient
        {
            public function describe(string $baseUrl): T3EnvironmentDescriptor
            {
                return new T3EnvironmentDescriptor(environmentId: 'env-host-reg', label: 'host-reg', serverVersion: '0.4.2');
            }

            public function describes(array $baseUrls, float $timeoutSeconds): array
            {
                $out = [];
                foreach ($baseUrls as $key => $url) {
                    $out[$key] = new T3EnvironmentDescriptor(environmentId: 'env-container', label: 'feat-one.acme', serverVersion: '0.4.2');
                }

                return $out;
            }

            public function session(string $baseUrl, string $adminSession): T3AdminSession
            {
                return new T3AdminSession(token: $adminSession, expiresAt: CarbonImmutable::now()->addDays(30), scopes: ['access:read', 'access:write']);
            }

            public function shell(string $baseUrl, string $adminSession): array
            {
                return ['threads' => [], 'archivedThreads' => []];
            }

            public function shells(array $servers, float $timeoutSeconds): array
            {
                return [];
            }
        });

        $runner = Mockery::mock(ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(static function (mixed $invocation): CommandResult {
            $argv = implode(' ', $invocation->command ?? []);
            if (str_contains($argv, 'ls-remote')) {
                return new CommandResult(0, str_repeat('a', 40)."\trefs/heads/main\n", '', 1, false);
            }
            if (str_contains($argv, 'rev-parse')) {
                return new CommandResult(0, str_repeat('a', 40)."\n", '', 1, false);
            }

            return new CommandResult(0, '', '', 1, false);
        });
        app()->instance(ProcessRunner::class, $runner);

        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('execute')->andReturnUsing(static function (mixed $conn, mixed $cmd): CommandResult {
            $argv = implode(' ', $cmd->command ?? []);
            if (str_contains($argv, 'python3')) {
                return new CommandResult(0, json_encode([
                    'state' => 'clean',
                    'baseline' => 'recorded',
                    'head' => 'main',
                    'head_commit' => str_repeat('a', 40),
                    'modified' => ['count' => 0, 'names' => []],
                    'untracked' => ['count' => 0, 'names' => []],
                    'stashes' => ['count' => 0, 'names' => []],
                    'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => [], 'head' => 0],
                ], JSON_THROW_ON_ERROR), '', 1, false);
            }

            return new CommandResult(0, "main\n".str_repeat('a', 40)."\n", '', 1, false);
        });
        app()->instance(SshExecutor::class, $ssh);

        // Domain records
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'code' => 'ACM',
            'type' => ProjectType::NodePackage,
            'repository_url' => 'https://gitlab.com/acme/app.git',
            'default_branch' => 'main',
            'root' => '.',
            'source_access' => ProjectSourceAccess::GitHubApp,
        ]);

        $defaultInstance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $host->id,
            'name' => 'default',
            'branch' => 'main',
            'checkout_path' => '/workspace/default',
            'status' => InstanceState::Active,
        ]);
        InstanceRuntime::query()->create([
            'node_id' => $host->id,
            'instance_id' => $defaultInstance->id,
            'power_state' => InstanceRuntimePowerState::Running->value,
            'runtime_version' => '0.4.2',
            'agent_version' => '0.30.0',
            'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$defaultInstance->id,
            'bridge_address' => '10.253.0.2',
            'seed_commit' => str_repeat('c', 40),
        ]);

        $cloneInstance = Instance::query()->forceCreate([
            'project_id' => $project->id,
            'node_id' => $host->id,
            'name' => 'feat-one',
            'branch' => 'feat-one',
            'checkout_path' => '/workspace/feat-one',
            'root' => '.',
            'status' => InstanceState::Active,
            'starting_commit' => str_repeat('a', 40),
        ]);
        InstanceRuntime::query()->create([
            'node_id' => $host->id,
            'instance_id' => $cloneInstance->id,
            'power_state' => InstanceRuntimePowerState::Running->value,
            'runtime_version' => '0.4.2',
            'agent_version' => '0.30.0',
            'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$cloneInstance->id,
            'bridge_address' => '10.253.0.3',
            'seed_commit' => str_repeat('c', 40),
            't3_port' => 3800 + $cloneInstance->id,
            'container_server' => 'conn',
        ]);

        $sleepingInstance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $host->id,
            'name' => 'asleep-one',
            'branch' => 'asleep-one',
            'checkout_path' => '/workspace/asleep-one',
            'status' => InstanceState::Active,
        ]);
        InstanceRuntime::query()->create([
            'node_id' => $host->id,
            'instance_id' => $sleepingInstance->id,
            'power_state' => InstanceRuntimePowerState::Stopped->value,
            'runtime_version' => '0.4.2',
            'agent_version' => '0.30.0',
            'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$sleepingInstance->id,
            'bridge_address' => '10.253.0.4',
            'seed_commit' => str_repeat('c', 40),
        ]);

        $containerEnv = ConnEnvironment::query()->forceCreate([
            'environment_id' => 'env-container',
            'label' => 'feat-one.acme',
            'url' => 'http://10.44.0.10:3773',
            'node_id' => $host->id,
            'instance_id' => $cloneInstance->id,
            'server_version' => '0.4.2',
            'admin_session' => 'admin-session-1',
            'admin_session_expires_at' => Carbon::now()->addDays(30),
            'registered_at' => Carbon::now(),
            'cleanup_version' => 2,
            'cleanup_completed_version' => 1,
        ]);

        $hostEnv = ConnEnvironment::query()->create([
            'environment_id' => 'env-host',
            'label' => 'beast-host',
            'url' => 'http://10.44.0.10:3774',
            'node_id' => $host->id,
            'instance_id' => null,
            'server_version' => '0.4.2',
            'admin_session' => 'admin-session-2',
            'admin_session_expires_at' => Carbon::now()->addDays(30),
            'registered_at' => Carbon::now(),
        ]);

        $managedEnv = ConnEnvironment::query()->create([
            'environment_id' => (string) Str::uuid(),
            'label' => 'managed-host',
            'url' => 'http://10.44.0.10:3775',
            'node_id' => $host->id,
            'instance_id' => null,
            'server_version' => '0.4.2',
            'admin_session' => 'admin-session-3',
            'admin_session_expires_at' => Carbon::now()->addDays(30),
            'registered_at' => Carbon::now(),
        ]);

        $credFile = tempnam(sys_get_temp_dir(), 'conn-openapi-svc-');
        expect($credFile)->toBeString();
        try {
            app(EnrolConnManagedTargetAction::class)->execute($managedEnv->environment_id, $credFile);
            $managedCredential = trim((string) file_get_contents($credFile));
        } finally {
            @unlink($credFile);
        }
        $managedTarget = ConnManagedTarget::query()->where('environment_id', $managedEnv->environment_id)->firstOrFail();
        $managedTarget->update(['revision' => 1]);
        ConnManagedRevision::query()->create([
            'conn_managed_target_id' => $managedTarget->id,
            'generation' => $managedTarget->generation,
            'revision' => 1,
            'edge_ids' => [1],
            'committed_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s.up'),
        ]);

        $covered = [];

        // 1-2. Profiles: create, list
        $profileResp = openapi_http($caller, 'POST', '/api/v1/conn/profiles', ['name' => 'Personal']);
        assert_response_matches_openapi($profileResp, 'POST', '/api/v1/conn/profiles', 201, $covered);
        $profileId = (int) $profileResp->json('data.id');

        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/profiles'), 'GET', '/api/v1/conn/profiles', 200, $covered);

        // 3-4. Me & bind profile
        assert_response_matches_openapi(openapi_http($caller, 'PUT', '/api/v1/conn/me/profile', ['profile_id' => $profileId]), 'PUT', '/api/v1/conn/me/profile', 200, $covered);
        $meResp = openapi_http($caller, 'GET', '/api/v1/conn/me');
        assert_response_matches_openapi($meResp, 'GET', '/api/v1/conn/me', 200, $covered);
        $profileChannel = (string) $meResp->json('data.profile_channel');

        // 5-7. Profile settings: show, update, patch
        $settingsShow = openapi_http($caller, 'GET', "/api/v1/conn/profiles/{$profileId}/settings");
        assert_response_matches_openapi($settingsShow, 'GET', '/api/v1/conn/profiles/{profile}/settings', 200, $covered);
        $version = (int) $settingsShow->json('data.version');

        $settingsPut = openapi_http($caller, 'PUT', "/api/v1/conn/profiles/{$profileId}/settings", [
            'version' => $version,
            'settings' => ['workspaces' => []],
        ]);
        assert_response_matches_openapi($settingsPut, 'PUT', '/api/v1/conn/profiles/{profile}/settings', 200, $covered);

        $settingsPatch = openapi_http($caller, 'PATCH', "/api/v1/conn/profiles/{$profileId}/settings", [
            'sections' => ['appearance' => ['version' => 0, 'value' => ['schema' => 1, 'mode' => 'dark', 'contrast' => 100]]],
        ]);
        assert_response_matches_openapi($settingsPatch, 'PATCH', '/api/v1/conn/profiles/{profile}/settings', 200, $covered);

        // 8-9. Conn realtime show & auth (bare {auth})
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/realtime'), 'GET', '/api/v1/conn/realtime', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', '/api/v1/conn/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => $profileChannel,
        ]), 'POST', '/api/v1/conn/broadcasting/auth', 200, $covered);

        // 10-15. Environments: list, register, renew, cleanup, session, unregister
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/environments'), 'GET', '/api/v1/conn/environments', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', '/api/v1/conn/environments', [
            'environment_id' => 'env-host-reg',
            'label' => 'host-reg',
            'url' => 'http://10.44.0.20:3773',
            'admin_session' => 'admin-secret-token',
        ]), 'POST', '/api/v1/conn/environments', 200, $covered);

        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/environments/{$containerEnv->environment_id}/renew"), 'POST', '/api/v1/conn/environments/{environment}/renew', 202, $covered);
        // Clear open renew operation so cleanup can be admitted cleanly
        Operation::query()->where('kind', 'conn.environment_renew')->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/environments/{$containerEnv->environment_id}/sessions/cleanup"), 'POST', '/api/v1/conn/environments/{environment}/sessions/cleanup', 202, $covered);
        Operation::query()->where('kind', 'conn.gateway_sessions_revoke')->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);

        // Held session (200 with ConnHeldSession)
        ConnPairing::query()->create([
            'node_id' => $caller->id,
            'conn_environment_id' => $containerEnv->id,
            'pairing_link_id' => 'held-link-1',
            'client_label' => 'phone',
            'expires_at' => Carbon::now()->addHour(),
            'held' => true,
            'session' => 'held-bearer-token',
            'session_expires_at' => Carbon::now()->addDay(),
        ]);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/environments/{$containerEnv->environment_id}/session"), 'GET', '/api/v1/conn/environments/{environment}/session', 200, $covered);

        assert_response_matches_openapi(openapi_http($caller, 'DELETE', "/api/v1/conn/environments/{$hostEnv->environment_id}"), 'DELETE', '/api/v1/conn/environments/{environment}', 200, $covered);

        // 16-19. Pairings: list, create, show, revoke
        $issuedPairing = ConnPairing::query()->create([
            'node_id' => $caller->id,
            'conn_environment_id' => $containerEnv->id,
            'pairing_link_id' => 'link-show-1',
            'client_label' => 'phone',
            'expires_at' => Carbon::now()->addHour(),
            'credential' => 'one-time-cred',
        ]);
        Operation::query()->create([
            'kind' => 'conn.pairing_create',
            'target_type' => 'conn_pairing',
            'target_id' => $issuedPairing->id,
            'node_id' => $host->id,
            'input' => ['caller_node_id' => $caller->id],
            'status' => OperationStatus::Succeeded->value,
        ]);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/environments/{$containerEnv->environment_id}/pairings?include_held=true"), 'GET', '/api/v1/conn/environments/{environment}/pairings', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/environments/{$containerEnv->environment_id}/pairings/{$issuedPairing->id}"), 'GET', '/api/v1/conn/environments/{environment}/pairings/{pairing}', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/environments/{$containerEnv->environment_id}/pairings", []), 'POST', '/api/v1/conn/environments/{environment}/pairings', 202, $covered);
        Operation::query()->where('kind', 'conn.pairing_create')->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/environments/{$containerEnv->environment_id}/pairings/revoke", []), 'POST', '/api/v1/conn/environments/{environment}/pairings/revoke', 202, $covered);
        Operation::query()->where('kind', 'conn.pairing_revoke')->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);

        // 20-21. Threads: record, list
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/environments/{$containerEnv->environment_id}/threads", [
            'thread_id' => 'thread-1',
            'title' => 'Investigate OpenAPI',
        ]), 'POST', '/api/v1/conn/environments/{environment}/threads', 201, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/threads'), 'GET', '/api/v1/conn/threads', 200, $covered);

        // 22-25. Projects, branches, name check, create instance
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/projects?include_unavailable=true'), 'GET', '/api/v1/conn/projects', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/projects/{$project->id}/branches"), 'GET', '/api/v1/conn/projects/{project}/branches', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/projects/{$project->id}/instances/check?name=new-clone"), 'GET', '/api/v1/conn/projects/{project}/instances/check', 200, $covered);
        $createdCloneResp = openapi_http($caller, 'POST', "/api/v1/conn/projects/{$project->id}/instances", [
            'name' => 'created-clone',
        ]);
        assert_response_matches_openapi($createdCloneResp, 'POST', '/api/v1/conn/projects/{project}/instances', 202, $covered);
        $createdCloneOpId = (int) $createdCloneResp->json('data.id');
        $createdCloneId = (int) $createdCloneResp->json('data.target_id');
        Operation::query()->whereKey($createdCloneOpId)->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);
        Instance::query()->whereKey($createdCloneId)->update(['status' => InstanceState::SourceResolved->value, 'starting_commit' => str_repeat('a', 40)]);
        ConnEnvironment::query()->forceCreate([
            'environment_id' => 'env-created-clone',
            'label' => 'created-clone.acme',
            'url' => 'http://10.44.0.10:3899',
            'node_id' => $host->id,
            'instance_id' => $createdCloneId,
            'server_version' => '0.4.2',
            'admin_session' => 'admin-session-clone',
            'admin_session_expires_at' => Carbon::now()->addDays(30),
            'registered_at' => Carbon::now(),
        ]);

        // 26-29. Reservations: create, show, finalize, release
        $resCreate = openapi_http($caller, 'POST', "/api/v1/conn/projects/{$project->id}/reservations");
        assert_response_matches_openapi($resCreate, 'POST', '/api/v1/conn/projects/{project}/reservations', 202, $covered);
        $resId = (string) $resCreate->json('data.id');

        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/reservations/{$resId}"), 'GET', '/api/v1/conn/reservations/{reservation}', 200, $covered);

        // Mark reservation's create operation succeeded and its draft clone ready so finalize can be admitted
        $reservation = ConnReservation::query()->findOrFail($resId);
        Operation::query()->whereKey($reservation->create_operation_id)->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);
        Instance::query()->whereKey($reservation->instance_id)->update(['status' => InstanceState::SourceResolved->value]);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/reservations/{$resId}/finalize", [
            'name' => 'finalized-clone',
        ], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/api/v1/conn/reservations/{reservation}/finalize', 202, $covered);

        // Create a second reservation to release
        $res2 = ConnReservation::query()->create([
            'project_id' => $project->id,
            'instance_id' => null,
            'node_id' => $caller->id,
            'conn_profile_id' => $profileId,
            'state' => ConnReservation::Open,
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);
        assert_response_matches_openapi(openapi_http($caller, 'DELETE', "/api/v1/conn/reservations/{$res2->id}"), 'DELETE', '/api/v1/conn/reservations/{reservation}', 202, $covered);

        // 30-32. Conn instances: work, complete, destroy (destroy uses a dedicated clone so $cloneInstance stays active for launches)
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/instances/{$cloneInstance->id}/work"), 'GET', '/api/v1/conn/instances/{instance_id}/work', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/instances/{$createdCloneId}/complete", [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/api/v1/conn/instances/{instance_id}/complete', 202, $covered);
        Operation::query()->where('kind', 'instance.complete')->where('target_id', $createdCloneId)->update(['status' => OperationStatus::Succeeded->value, 'lock_key' => null]);
        Instance::query()->whereKey($createdCloneId)->update(['status' => InstanceState::Active->value]);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/instances/{$createdCloneId}/destroy", ['acknowledge_unsaved' => true], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/api/v1/conn/instances/{instance_id}/destroy', 202, $covered);

        // 33-34. T3 restarts: instance, node
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/instances/{$defaultInstance->id}/t3-restart"), 'POST', '/api/v1/conn/instances/{instance}/t3-restart', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/nodes/{$host->id}/t3-restart"), 'POST', '/api/v1/conn/nodes/{node}/t3-restart', 200, $covered);

        // 35-36. Conn operations: show, cancel
        $connOp = Operation::query()->create([
            'kind' => 'conn.pairing_create',
            'target_type' => 'conn_pairing',
            'target_id' => $issuedPairing->id,
            'node_id' => $host->id,
            'input' => ['caller_node_id' => $caller->id],
            'status' => OperationStatus::Running->value,
            'state' => [
                'native_progress' => [
                    'run' => 'run-1',
                    'seq' => 1,
                    'active' => [],
                    'steps' => [
                        'k1' => ['name' => 'issue', 'job' => 'main', 'status' => 'succeeded', 'started_at' => '2026-11-22T20:00:00Z', 'finished_at' => '2026-11-22T20:00:01Z'],
                    ],
                ],
            ],
        ]);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/operations/{$connOp->id}"), 'GET', '/api/v1/conn/operations/{operation}', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/operations/{$connOp->id}/cancel"), 'POST', '/api/v1/conn/operations/{operation}/cancel', 200, $covered);

        // 37-41. Conn launches: create, list, show, retry, cancel
        $launchCreate = openapi_http($caller, 'POST', '/api/v1/conn/launches', [
            'expected_profile_id' => $profileId,
            'expected_node_id' => $caller->id,
            'project_id' => $project->id,
            'workspace_id' => null,
            'target' => ['kind' => 'existing', 'instance_id' => $cloneInstance->id],
            'prompt' => 'Hello world',
            'model_selection' => ['model' => 'claude-sonnet-4-6', 'provider' => 'claude', 'options' => [['id' => 'effort', 'value' => 'medium']]],
            'runtime_mode' => 'auto',
            'interaction_mode' => 'default',
            'source' => 'web',
            'command_id' => (string) Str::uuid(),
            'thread_id' => (string) Str::uuid(),
            'message_id' => (string) Str::uuid(),
        ], ['Idempotency-Key' => (string) Str::uuid()]);
        assert_response_matches_openapi($launchCreate, 'POST', '/api/v1/conn/launches', 202, $covered);
        $launchOpId = (int) $launchCreate->json('data.id');

        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/launches'), 'GET', '/api/v1/conn/launches', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/launches/{$launchOpId}"), 'GET', '/api/v1/conn/launches/{operation_id}', 200, $covered);

        // Put launch in needs_retry so retry succeeds, then cancel
        $launchModel = Operation::query()->findOrFail($launchOpId);
        $launchState = $launchModel->state ?? [];
        $launchState['launch_phase'] = 'needs_retry';
        $launchModel->update(['status' => OperationStatus::Waiting->value, 'state' => $launchState]);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/launches/{$launchOpId}/retry", ['target' => 'launch']), 'POST', '/api/v1/conn/launches/{operation_id}/retry', 202, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/conn/launches/{$launchOpId}/cancel"), 'POST', '/api/v1/conn/launches/{operation_id}/cancel', 202, $covered);

        // 42-43. Conn tasks: list, show
        $taskGroup = Task::topLevel()->create([
            'project_id' => $project->id,
            'title' => 'Ship OpenAPI',
            'brief' => 'Generate exact schemas.',
            'status' => TaskGroupStatus::Running,
        ]);
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/conn/tasks'), 'GET', '/api/v1/conn/tasks', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/conn/tasks/{$taskGroup->id}"), 'GET', '/api/v1/conn/tasks/{task}', 200, $covered);

        // 44-49. Managed routes: token create + 5 managed target routes
        $tokenResp = openapi_http($caller, 'POST', "/api/v1/conn/environments/{$managedEnv->environment_id}/managed-tokens", ['purpose' => 'reader']);
        assert_response_matches_openapi($tokenResp, 'POST', '/api/v1/conn/environments/{environment}/managed-tokens', 201, $covered);
        $readerToken = (string) $tokenResp->json('data.token');

        $managedHeaders = ['Authorization' => 'Bearer '.$managedCredential];
        $managedBase = ['environment_id' => $managedEnv->environment_id, 'generation' => $managedTarget->generation];

        $managedRealtime = openapi_http($host, 'POST', '/api/v1/conn/managed/realtime', $managedBase, $managedHeaders);
        assert_response_matches_openapi($managedRealtime, 'POST', '/api/v1/conn/managed/realtime', 200, $covered);
        $managedChannel = (string) $managedRealtime->json('data.channel');

        $managedBroadcast = openapi_http($host, 'POST', '/api/v1/conn/managed/broadcasting/auth', [
            ...$managedBase,
            'socket_id' => '123.456',
            'channel_name' => $managedChannel,
        ], $managedHeaders);
        assert_response_matches_openapi($managedBroadcast, 'POST', '/api/v1/conn/managed/broadcasting/auth', 200, $covered);
        expect(array_keys((array) openapi_decode_json((string) $managedBroadcast->getContent())))->toBe(['auth']);

        assert_response_matches_openapi(openapi_http($host, 'POST', '/api/v1/conn/managed/authority', [
            ...$managedBase,
            'nonce' => 'nonce_1234567890abcdef',
            'after_revision' => null,
        ], $managedHeaders), 'POST', '/api/v1/conn/managed/authority', 200, $covered);

        assert_response_matches_openapi(openapi_http($host, 'POST', '/api/v1/conn/managed/introspect', [
            ...$managedBase,
            'nonce' => 'nonce_1234567890abcdef',
            'token' => $readerToken,
            'peer_address' => $caller->wireguard_ip,
        ], $managedHeaders), 'POST', '/api/v1/conn/managed/introspect', 200, $covered);

        assert_response_matches_openapi(openapi_http($host, 'POST', '/api/v1/conn/managed/ack', [
            ...$managedBase,
            'revision' => 1,
            'kind' => 'observed',
            'notice_received_at' => '2026-11-22T20:00:00Z',
            'fenced_at' => '2026-11-22T20:00:01Z',
            'handlers_closed_at' => '2026-11-22T20:00:02Z',
        ], $managedHeaders), 'POST', '/api/v1/conn/managed/ack', 200, $covered);

        // 50-55. The 6 operations Conn calls outside /api/v1/conn
        assert_response_matches_openapi(openapi_http($caller, 'GET', "/api/v1/operations/{$connOp->id}"), 'GET', '/api/v1/operations/{operation}', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'POST', "/api/v1/instances/{$sleepingInstance->id}/wake"), 'POST', '/api/v1/instances/{instance}/wake', 202, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/realtime'), 'GET', '/api/v1/realtime', 200, $covered);

        $broadcastResp = openapi_http($caller, 'POST', '/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-orbit',
        ]);
        assert_response_matches_openapi($broadcastResp, 'POST', '/api/v1/broadcasting/auth', 200, $covered);
        expect(array_keys((array) openapi_decode_json((string) $broadcastResp->getContent())))->toBe(['auth']);

        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/task-insights?period=7d'), 'GET', '/api/v1/task-insights', 200, $covered);
        assert_response_matches_openapi(openapi_http($caller, 'GET', '/api/v1/projects'), 'GET', '/api/v1/projects', 200, $covered);

        // Verify 100% coverage of every /api/v1/conn operation and all 6 outside operations in docs/openapi.json
        $spec = openapi_document();
        $outside = ['operation-show', 'instance-wake', 'realtime-show', 'realtime-auth', 'task-insights', 'project-list'];
        $expectedOps = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $op) {
                $oid = (string) ($op['operationId'] ?? '');
                if (str_starts_with($path, '/api/v1/conn') || in_array($oid, $outside, true)) {
                    $expectedOps[] = $oid;
                }
            }
        }
        sort($expectedOps);
        $actualOps = array_keys($covered);
        sort($actualOps);
        expect($actualOps)->toBe($expectedOps);
    });

    it('matches the real Error envelope for empty and non-empty details and declares auth schemes accurately', function (): void {
        config(['orbit.conn.managed_targets' => true]);
        app(ExtensionStore::class)->set('conn', true);
        $spec = openapi_document();
        $errorSchema = $spec['components']['schemas']['Error'];

        $caller = openapi_node('phone', '10.44.0.21');
        $gateway = openapi_node('gw', '10.44.0.2', gateway: true);
        $caller->accessibleNodes()->attach($gateway->id);

        // Error with empty details from ApiErrorResponse::make (and verify Error schema accepts both [] and {})
        $emptyDetails = openapi_http($caller, 'GET', '/api/v1/conn/operations/999999');
        $emptyDetails->assertNotFound();
        expect($emptyDetails->headers->get('X-Orbit-Request-Id'))->not->toBeEmpty();
        $emptyDecoded = openapi_decode_json((string) $emptyDetails->getContent());
        expect(property_exists($emptyDecoded->error, 'request_id'))->toBeFalse();
        expect(openapi_problems($emptyDecoded, $errorSchema, $spec))->toBe([]);
        expect(openapi_problems(openapi_decode_json('{"error":{"code":"http.404","message":"Resource not found.","details":[]}}'), $errorSchema, $spec))->toBe([]);

        // Error from ResourceOperationException (empty details or object)
        $managedUnknown = openapi_http($caller, 'POST', '/api/v1/conn/managed/realtime', [
            'environment_id' => 'does-not-exist',
            'generation' => 1,
        ]);
        $managedUnknown->assertNotFound();
        expect(openapi_problems(openapi_decode_json((string) $managedUnknown->getContent()), $errorSchema, $spec))->toBe([]);

        // Error with non-empty details ({...})
        $nonEmptyDetails = openapi_http($caller, 'POST', '/api/v1/conn/profiles', ['name' => '']);
        $nonEmptyDetails->assertUnprocessable();
        expect($nonEmptyDetails->headers->get('X-Orbit-Request-Id'))->not->toBeEmpty();
        $nonEmptyDecoded = openapi_decode_json((string) $nonEmptyDetails->getContent());
        expect($nonEmptyDecoded->error->details)->toBeInstanceOf(stdClass::class);
        expect(openapi_problems($nonEmptyDecoded, $errorSchema, $spec))->toBe([]);

        // Negative validation proof: missing required field and wrong nullability are rejected by the schema
        $missingRequired = (object) ['environment_id' => 'env-1'];
        expect(openapi_problems($missingRequired, $spec['components']['schemas']['ConnHeldSession'], $spec))
            ->not->toBeEmpty();

        $nullOnNonNullable = (object) ['url' => null, 'key' => null, 'channel' => null];
        expect(openapi_problems($nullOnNonNullable, $spec['components']['schemas']['RealtimeConfig'], $spec))
            ->toContain('body.channel is null, expected string');

        $nullOnNullable = (object) ['url' => null, 'key' => null, 'channel' => 'orbit'];
        expect(openapi_problems($nullOnNullable, $spec['components']['schemas']['RealtimeConfig'], $spec))
            ->toBe([]);

        // Auth declarations
        expect($spec['components']['securitySchemes'])->toHaveKeys(['wireguardPeer', 'managedTargetBearer'])
            ->and($spec['security'])->toBe([['wireguardPeer' => []]])
            ->and($spec['paths']['/api/v1/gateway/status']['get']['security'])->toBe([])
            ->and($spec['paths']['/api/v1/ca/root']['get']['security'])->toBe([]);

        foreach (['/api/v1/conn/managed/realtime', '/api/v1/conn/managed/broadcasting/auth', '/api/v1/conn/managed/authority', '/api/v1/conn/managed/introspect', '/api/v1/conn/managed/ack'] as $managedPath) {
            expect($spec['paths'][$managedPath]['post']['security'])
                ->toBe([['wireguardPeer' => [], 'managedTargetBearer' => []]]);
        }

        // MCP tools for managed routes have their real per-route inputs
        $catalogue = json_decode((string) file_get_contents(dirname(base_path(), 2).'/apps/gateway/resources/mcp/tools.json'), true, 512, JSON_THROW_ON_ERROR);
        $tools = collect($catalogue['tools'])->keyBy('name');
        expect(array_keys($tools['conn-managed-realtime']['input_schema']['properties']))
            ->toBe(['environment_id', 'generation'])
            ->and(array_keys($tools['conn-managed-broadcasting-auth']['input_schema']['properties']))
            ->toBe(['environment_id', 'generation', 'socket_id', 'channel_name'])
            ->and(array_keys($tools['conn-managed-authority']['input_schema']['properties']))
            ->toBe(['environment_id', 'generation', 'nonce', 'after_revision'])
            ->and(array_keys($tools['conn-managed-introspect']['input_schema']['properties']))
            ->toBe(['environment_id', 'generation', 'nonce', 'token', 'peer_address'])
            ->and(array_keys($tools['conn-managed-ack']['input_schema']['properties']))
            ->toBe(['environment_id', 'generation', 'revision', 'kind', 'notice_received_at', 'fenced_at', 'handlers_closed_at', 'recovered_at']);
    });
});
