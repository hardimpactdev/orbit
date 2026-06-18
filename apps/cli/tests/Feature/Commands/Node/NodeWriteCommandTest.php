<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\Node\NodeGatewayBootstrapper;
use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

describe('node write commands', function (): void {
    it('posts node:new payloads to the typed gateway API', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => fakeSuccessEnvelope([
                'node' => ['name' => 'app-1'],
                'action' => 'created',
            ]),
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev,database',
            '--host' => '192.0.2.20',
            '--gateway-endpoint' => '10.3.0.2',
            '--tld' => 'test',
            '--grant-to' => ['agent-1'],
            '--agent-tool' => ['openclaw'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes')
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request['name'] === 'app-1'
            && $request['roles'] === ['app-dev', 'database']
            && $request['host'] === '192.0.2.20'
            && $request['gateway_endpoint'] === '10.3.0.2'
            && $request['tld'] === 'test'
            && $request['grant_to'] === ['agent-1']
            && $request['agent_tools'] === ['openclaw']
            && ! isset($request['template'])
            && ! isset($request['operator']));

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data'])->toBe($complete);
    });

    it('normalizes comma-separated node:new roles for programmatic callers', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => fakeSuccessEnvelope([
                'node' => ['name' => 'app-1'],
                'action' => 'created',
            ]),
        ]));

        [$exitCode] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev, database',
            '--host' => '192.0.2.20',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes')
            && $request['roles'] === ['app-dev', 'database']
            && ! isset($request['template']));

        expect($exitCode)->toBe(0);
    });

    it('accepts metrics role node:new payloads for programmatic callers', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => fakeSuccessEnvelope([
                'node' => ['name' => 'metrics-1'],
                'action' => 'created',
            ]),
        ]));

        [$exitCode] = runCommand($this, 'node:new', [
            'name' => 'metrics-1',
            '--roles' => 'metrics',
            '--host' => '192.0.2.55',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes')
            && $request['roles'] === ['metrics']
            && ! isset($request['template']));

        expect($exitCode)->toBe(0);
    });

    it('runs the bootstrap path for first gateway node creation when no gateway is configured', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance(GatewayApiClient::class);
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-new-bootstrap-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        $bootstrapper = new class extends NodeGatewayBootstrapper
        {
            /** @var list<string> */
            public array $arguments = [];

            /**
             * @param  list<string>  $arguments
             * @return array{exit_code: int, output: string}
             */
            public function run(array $arguments): array
            {
                $this->arguments = $arguments;

                return [
                    'exit_code' => 0,
                    'output' => json_encode(JsonEnvelope::success([
                        'node' => ['name' => 'gateway-1'],
                        'action' => 'bootstrapped',
                    ]), JSON_THROW_ON_ERROR),
                ];
            }
        };

        app()->instance(NodeGatewayBootstrapper::class, $bootstrapper);
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'gateway-1',
            '--template' => 'gateway',
            '--host' => '192.0.2.10',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($bootstrapper->arguments)->toContain('node:new')
            ->and($bootstrapper->arguments)->toContain('gateway-1')
            ->and($bootstrapper->arguments)->toContain('--template=gateway')
            ->and($bootstrapper->arguments)->toContain('--host=192.0.2.10')
            ->and($bootstrapper->arguments)->toContain('--json');

        @unlink($store->path());
    });

    it('returns the gateway_bootstrap_unavailable envelope when orbit-gateway is not available', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance(GatewayApiClient::class);
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-new-unavailable-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        Process::fake([
            '*' => Process::result(exitCode: 1),
        ]);

        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'gateway-1',
            '--template' => 'gateway',
            '--host' => '192.0.2.10',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_bootstrap_unavailable')
            ->and($decoded['error']['meta']['container'])->toBe('orbit-gateway');

        Process::assertRan(fn ($process): bool => $process->command === [
            'docker', 'exec', 'orbit-gateway', 'test', '-f', 'apps/gateway/artisan',
        ]);

        @unlink($store->path());
    });

    it('routes the gateway bootstrap through docker exec orbit-gateway when the runtime is available', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance(GatewayApiClient::class);
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-new-docker-exec-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        $successOutput = json_encode(JsonEnvelope::success([
            'node' => ['name' => 'gateway-1'],
            'action' => 'bootstrapped',
        ]), JSON_THROW_ON_ERROR);

        Process::fake([
            '*' => Process::result(output: $successOutput, exitCode: 0),
        ]);

        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'gateway-1',
            '--template' => 'gateway',
            '--host' => '192.0.2.10',
            '--json' => true,
        ]);

        Http::assertNothingSent();

        expect($exitCode)->toBe(0);

        Process::assertRan(fn ($process): bool => $process->command === [
            'docker', 'exec', 'orbit-gateway', 'test', '-f', 'apps/gateway/artisan',
        ]);

        Process::assertRan(fn ($process): bool => is_array($process->command)
            && $process->command[0] === 'docker'
            && $process->command[1] === 'exec'
            && $process->command[2] === 'orbit-gateway'
            && $process->command[3] === 'php'
            && $process->command[4] === 'apps/gateway/artisan'
            && in_array('node:new', $process->command, strict: true)
            && in_array('gateway-1', $process->command, strict: true)
            && in_array('--template=gateway', $process->command, strict: true)
            && in_array('--host=192.0.2.10', $process->command, strict: true));

        @unlink($store->path());
    });

    it('rejects mutually exclusive node:new role inputs before gateway IO', function (array $params, array $fields): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:new', array_merge([
            'name' => 'app-1',
            '--json' => true,
        ], $params));

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])->toBe($fields);
    })->with([
        'template plus roles' => [['--template' => 'app-development', '--roles' => 'app-dev'], ['template', 'roles']],
        'operator plus roles' => [['--operator' => true, '--roles' => 'app-dev'], ['operator', 'roles']],
        'operator plus non-operator template' => [['--operator' => true, '--template' => 'app-development'], ['operator', 'template']],
    ]);

    it('rejects non-canonical node:new roles before gateway IO', function (string $roles): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => $roles,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('roles');
    })->with([
        'app-development',
        'app-production',
        'gateway',
        'vpn',
        'router',
    ]);

    it('puts node:update payloads to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'changed' => ['host', 'tld', 'gateway_endpoint'],
            'action' => 'updated',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:update', [
            'name' => 'app-1',
            '--host' => '192.0.2.21',
            '--tld' => 'dev',
            '--gateway-endpoint' => '10.3.0.2',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/api/nodes/app-1')
            && $request['host'] === '192.0.2.21'
            && $request['tld'] === 'dev'
            && $request['gateway_endpoint'] === '10.3.0.2'
            && ! isset($request['environment']));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['action'])->toBe('updated');
    });

    it('validates node:update required input before gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:update', [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'missing name' => [[], 'name'],
        'missing update fields' => [['name' => 'app-1'], 'fields'],
    ]);

    it('requires --force before node:remove sends destructive gateway requests', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:remove', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('validates node:remove names before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:remove', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('deletes nodes through the typed gateway API when force is supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'action' => 'removed',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/nodes/app-1')
            && $request['destructive_consent'] === true);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['action'])->toBe('removed');
    });

    it('renders node:remove human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'action' => 'removed',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Removing node 'app-1'")
            ->and($output)->toContain('Remove node record')
            ->and($output)->toContain("Node 'app-1' removed")
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', "This node is not authorized for 'node:remove' on 'app-1'."), 403);

        [$exitCode, $output] = runCommand($this, 'node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('not authorized')
            ->and($output)->not->toContain('"error"');
    });

    it('posts node:grant payloads to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            'action' => 'granted',
        ]));

        [$exitCode] = runCommand($this, 'node:grant', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--preset' => 'developer',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/grant')
            && $request['consuming_node'] === 'agent-1'
            && $request['serving_node'] === 'app-1'
            && $request['preset'] === 'developer'
            && $request['force'] === true);

        expect($exitCode)->toBe(0);
    });

    it('validates node:grant required inputs before gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:grant', [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'missing consuming node' => [[], 'consuming_node'],
        'missing serving node' => [['consuming_node' => 'agent-1'], 'serving_node'],
    ]);

    it('requires --force before node:revoke sends destructive gateway requests', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:revoke', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('posts node:revoke payloads to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            'action' => 'revoked',
        ]));

        [$exitCode] = runCommand($this, 'node:revoke', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/revoke')
            && $request['consuming_node'] === 'agent-1'
            && $request['serving_node'] === 'app-1'
            && $request['force'] === true);

        expect($exitCode)->toBe(0);
    });

    it('renders node:revoke human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            'action' => 'revoked',
            'already_absent' => false,
            'self_lockout' => false,
            'was_gateway_admin' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'node:revoke', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Revoking Grant')
            ->and($output)->toContain('Revoke access')
            ->and($output)->toContain("Access from 'operator-1' to 'app-1' revoked")
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:revoke idempotent absent grants as already revoked prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            'action' => 'revoked',
            'already_absent' => true,
            'self_lockout' => false,
            'was_gateway_admin' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'node:revoke', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Access from 'operator-1' to 'app-1' was already revoked")
            ->and($output)->not->toContain('{');
    });

    it('renders node:revoke self-lockout warning after the tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'operator-1',
            'serving_node' => 'gateway-1',
            'action' => 'revoked',
            'already_absent' => false,
            'self_lockout' => true,
            'was_gateway_admin' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'node:revoke', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'gateway-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Access from 'operator-1' to 'gateway-1' revoked")
            ->and($output)->toContain('This machine no longer has Orbit gateway access.');
    });

    it('renders node:revoke gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This action requires the node:revoke permission on a grant to the gateway.'), 403);

        [$exitCode, $output] = runCommand($this, 'node:revoke', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('node:revoke permission')
            ->and($output)->not->toContain('"error"');
    });

    it('rejects ambiguous node:permissions modes before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:permissions', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--preset' => 'developer',
            '--add' => 'tool:read',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed');
    });

    it('posts node:permissions payloads to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            'permissions' => ['tool:read'],
        ]));

        [$exitCode] = runCommand($this, 'node:permissions', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--permissions' => 'tool:read',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/permissions')
            && $request['consuming_node'] === 'agent-1'
            && $request['serving_node'] === 'app-1'
            && $request['permissions'] === 'tool:read');

        expect($exitCode)->toBe(0);
    });

    it('renders node:permissions mutations as a progress tree with prose detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            'action' => 'updated',
            'mode' => 'permissions',
            'permissions' => ['node:read', 'tool:read'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:permissions', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--permissions' => 'tool:read,node:read',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Updating Node Permissions')
            ->and($output)->toContain('Apply permission change')
            ->and($output)->toContain("Permissions for 'agent-1' on 'app-1' updated")
            ->and($output)->toContain('Permissions: node:read, tool:read')
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:permissions read mode as prose without a tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            'action' => 'read',
            'mode' => 'read',
            'permissions' => ['node:read', 'tool:read'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:permissions', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Permissions for 'agent-1' on 'app-1': node:read, tool:read")
            ->and($output)->not->toContain('Updating Node Permissions')
            ->and($output)->not->toContain('{');
    });

    it('renders node:permissions redundant-permission warnings after the tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            'action' => 'updated',
            'mode' => 'permissions',
            'permissions' => ['tool:read'],
        ], [
            'warnings' => [[
                'code' => 'node.redundant_permissions',
                'family' => 'node',
                'message' => 'Redundant permissions were removed: tool:list.',
                'next_command' => null,
                'permissions' => ['tool:list'],
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:permissions', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--permissions' => 'tool:read,tool:list',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Redundant permissions were removed: tool:list.')
            ->and($output)->not->toContain('{');
    });

    it('renders node:permissions gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This action requires the node:permissions permission on a grant to the gateway.'), 403);

        [$exitCode, $output] = runCommand($this, 'node:permissions', [
            'consuming_node' => 'agent-1',
            'serving_node' => 'app-1',
            '--permissions' => 'tool:read',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('node:permissions permission')
            ->and($output)->not->toContain('"error"');
    });

    it('posts node role:add payloads to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'assignment' => ['role' => 'app-dev', 'status' => 'active'],
        ]));

        [$exitCode] = runCommand($this, 'node role:add', [
            'node' => 'app-1',
            'role' => 'app-dev',
            '--tld' => 'test',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/app-1/roles')
            && $request['role'] === 'app-dev'
            && $request['settings'] === ['tld' => 'test']);

        expect($exitCode)->toBe(0);
    });

    it('renders node role:add human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'assignment' => ['role' => 'app-dev', 'status' => 'active'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:add', [
            'node' => 'app-1',
            'role' => 'app-dev',
            '--tld' => 'test',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Adding Node Role')
            ->and($output)->toContain('Apply role convergence')
            ->and($output)->toContain("Role 'app-dev' added to 'app-1'")
            ->and($output)->not->toContain('node:')
            ->and($output)->not->toContain('{');
    });

    it('renders node role:add gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('node.not_found', "Node 'app-1' not found."), 404);

        [$exitCode, $output] = runCommand($this, 'node role:add', [
            'node' => 'app-1',
            'role' => 'app-dev',
            '--tld' => 'test',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Node 'app-1' not found.")
            ->and($output)->not->toContain('"error"');
    });

    it('posts analytics node:new backing node selectors to the typed gateway API', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => fakeSuccessEnvelope([
                'node' => ['name' => 'analytics-1'],
                'action' => 'created',
            ]),
        ]));

        [$exitCode] = runCommand($this, 'node:new', [
            'name' => 'analytics-1',
            '--roles' => 'analytics',
            '--host' => '192.0.2.30',
            '--postgres-node' => 'database-1',
            '--clickhouse-node' => 'database-2',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes')
            && $request['roles'] === ['analytics']
            && $request['postgres_node'] === 'database-1'
            && $request['clickhouse_node'] === 'database-2');

        expect($exitCode)->toBe(0);
    });

    it('posts node role:add analytics backing node selectors to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'analytics-1',
            'assignment' => ['role' => 'analytics', 'status' => 'active'],
        ]));

        [$exitCode] = runCommand($this, 'node role:add', [
            'node' => 'analytics-1',
            'role' => 'analytics',
            '--postgres-node' => 'database-1',
            '--clickhouse-node' => 'database-2',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/analytics-1/roles')
            && $request['role'] === 'analytics'
            && $request['settings'] === [
                'postgres_node' => 'database-1',
                'clickhouse_node' => 'database-2',
            ]);

        expect($exitCode)->toBe(0);
    });

    it('requires analytics backing node selectors before node:new gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'analytics-1',
            '--roles' => 'analytics',
            '--json' => true,
            ...$params,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'missing postgres' => [['--clickhouse-node' => 'database-2'], 'postgres_node'],
        'missing clickhouse' => [['--postgres-node' => 'database-1'], 'clickhouse_node'],
    ]);

    it('requires analytics backing node selectors before node role:add gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node role:add', [
            'node' => 'analytics-1',
            'role' => 'analytics',
            '--json' => true,
            ...$params,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'missing postgres' => [['--clickhouse-node' => 'database-2'], 'postgres_node'],
        'missing clickhouse' => [['--postgres-node' => 'database-1'], 'clickhouse_node'],
    ]);

    it('rejects gateway-coupled node role:add roles before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node role:add', [
            'node' => 'app-1',
            'role' => 'gateway',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['role'])->toBe('gateway');
    });

    it('requires --force before node role:remove sends destructive gateway requests', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'node role:remove', [
            'node' => 'app-1',
            'role' => 'database',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes node roles through the typed gateway API when force is supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'role' => 'database',
            'purged_data' => true,
        ]));

        [$exitCode] = runCommand($this, 'node role:remove', [
            'node' => 'app-1',
            'role' => 'database',
            '--force' => true,
            '--purge-data' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/nodes/app-1/roles/database')
            && $request['force'] === true
            && $request['purge_data'] === true);

        expect($exitCode)->toBe(0);
    });

    it('renders node role:remove human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'role' => 'database',
            'purged_data' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:remove', [
            'node' => 'app-1',
            'role' => 'database',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Removing Node Role')
            ->and($output)->toContain('Remove role convergence')
            ->and($output)->toContain("Role 'database' removed from 'app-1'")
            ->and($output)->not->toContain('node:')
            ->and($output)->not->toContain('{');
    });

    it('reports purged data in the node role:remove success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'role' => 'database',
            'purged_data' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:remove', [
            'node' => 'app-1',
            'role' => 'database',
            '--force' => true,
            '--purge-data' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Role 'database' removed from 'app-1' with data purged")
            ->and($output)->not->toContain('{');
    });

    it('renders node role:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('node_role.remove_blocked', "Role 'database' cannot be removed while dependents exist."), 422);

        [$exitCode, $output] = runCommand($this, 'node role:remove', [
            'node' => 'app-1',
            'role' => 'database',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('cannot be removed while dependents exist')
            ->and($output)->not->toContain('"error"');
    });

    it('posts node:agent-ide payloads to the typed gateway API', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'agent_ide' => ['adapter' => 'opencode', 'source' => 'node'],
            'action' => 'updated',
        ]));

        [$exitCode] = runCommand($this, 'node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/app-1/agent-ide')
            && $request['agent_ide'] === 'opencode');

        expect($exitCode)->toBe(0);
    });

    it('renders node:agent-ide set success as prose without a tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'agent_ide' => ['adapter' => 'opencode', 'source' => 'node'],
            'action' => 'set',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Node 'app-1' agent IDE set to 'opencode'")
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:agent-ide converged success as already-set prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'agent_ide' => ['adapter' => 'opencode', 'source' => 'node'],
            'action' => 'converged',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Node 'app-1' agent IDE already set to 'opencode'")
            ->and($output)->not->toContain('{');
    });

    it('renders node:agent-ide clear success as cleared prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'agent_ide' => ['adapter' => null, 'source' => 'default'],
            'action' => 'set',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'none',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Node 'app-1' agent IDE cleared")
            ->and($output)->not->toContain('{');
    });

    it('renders node:agent-ide gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('node.unsupported_adapter', "Adapter 'unknown-ide' is not supported."), 422);

        [$exitCode, $output] = runCommand($this, 'node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'unknown-ide',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Adapter 'unknown-ide' is not supported.")
            ->and($output)->not->toContain('"error"');
    });

    it('renders node:grant new grant success as prose without a tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            'action' => 'granted',
            'already_granted' => false,
            'permissions' => ['node:read', 'tool:read'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:grant', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--preset' => 'developer',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Granted 'operator-1' access to 'app-1'")
            ->and($output)->toContain('Permissions: node:read, tool:read')
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:grant idempotent already-granted success as prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            'action' => 'granted',
            'already_granted' => true,
            'permissions' => ['node:read', 'tool:read'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:grant', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--preset' => 'developer',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("'operator-1' already has access to 'app-1'")
            ->and($output)->toContain('Permissions: node:read, tool:read')
            ->and($output)->toContain('Run `orbit node:permissions operator-1 app-1` to edit this grant.')
            ->and($output)->not->toContain('{');
    });

    it('renders node:grant redundant-permission warnings after the grant line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            'action' => 'granted',
            'already_granted' => false,
            'permissions' => ['tool:read'],
        ], [
            'warnings' => [[
                'code' => 'node.redundant_permissions',
                'family' => 'node',
                'message' => 'Redundant permissions were removed: tool:list.',
                'next_command' => null,
                'permissions' => ['tool:list'],
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:grant', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--permissions' => 'tool:read,tool:list',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Granted 'operator-1' access to 'app-1'")
            ->and($output)->toContain('Redundant permissions were removed: tool:list.')
            ->and($output)->not->toContain('{');
    });

    it('renders node:grant gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('node.not_found', "Serving node 'app-1' not found."), 404);

        [$exitCode, $output] = runCommand($this, 'node:grant', [
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-1',
            '--preset' => 'developer',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Serving node 'app-1' not found.")
            ->and($output)->not->toContain('"error"');
    });

    it('renders node:update human output as a progress tree with changed detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'changed' => ['host', 'public_ipv4'],
            'action' => 'updated',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:update', [
            'name' => 'app-1',
            '--host' => '192.0.2.21',
            '--public-ipv4' => '192.0.2.21',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Updating Node')
            ->and($output)->toContain('Apply and verify node change')
            ->and($output)->toContain("Node 'app-1' updated")
            ->and($output)->toContain('Changed: host, public_ipv4')
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:update no-op changes as unchanged prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'changed' => [],
            'action' => 'updated',
        ]));

        [$exitCode, $output] = runCommand($this, 'node:update', [
            'name' => 'app-1',
            '--host' => '192.0.2.21',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Node 'app-1' unchanged")
            ->and($output)->toContain('No fields were modified.')
            ->and($output)->not->toContain('{');
    });

    it('renders node:update drift warnings after the tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'app-1',
            'changed' => ['host'],
            'action' => 'updated',
        ], [
            'warnings' => [[
                'code' => 'node.artifact_enactment_failed',
                'family' => 'node',
                'message' => 'Node artifact re-enactment failed after intent update.',
                'next_command' => 'doctor --family=node --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:update', [
            'name' => 'app-1',
            '--host' => '192.0.2.21',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Node 'app-1' updated with drift")
            ->and($output)->toContain('Changed: host')
            ->and($output)->toContain('Drift detected:')
            ->and($output)->not->toContain('{');
    });

    it('renders node:update gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', "This node is not authorized for 'node:update' on 'app-1'."), 403);

        [$exitCode, $output] = runCommand($this, 'node:update', [
            'name' => 'app-1',
            '--host' => '192.0.2.21',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('not authorized')
            ->and($output)->not->toContain('"error"');
    });
});
