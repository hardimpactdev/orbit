<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('tool write commands', function (): void {
    beforeEach(function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-write-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);
    });

    afterEach(function (): void {
        @unlink(base_path('tests/.tmp-tool-write-config.json'));
    });

    it('prompts for tool and target before installing in interactive mode', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'state' => 'installed'],
            ],
        ]));

        $this->artisan('tool:install')
            ->expectsQuestion('Tool name', 'redis')
            ->expectsQuestion('Target node', 'app-1')
            ->expectsOutputToContain('redis')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/redis/install'
            && $request->data() === [
                'node' => 'app-1',
                'status' => 'installed',
            ]);
    });

    it('streams tool:install payloads to the gateway', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'state' => 'running'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--status' => 'running',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/redis/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'app-1',
                'status' => 'running',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['state'])->toBe('running');
    });

    it('streams tool:install version and runtime intent to the gateway', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => [
                    'name' => 'mysql',
                    'node' => 'database-1',
                    'instance' => 'mysql:8',
                    'version_family' => '8',
                    'version' => '8.4',
                    'runtime' => 'docker-swarm',
                    'state' => 'running',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'mysql',
            '--node' => 'database-1',
            '--version' => '8.4',
            '--runtime' => 'docker-swarm',
            '--status' => 'running',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/mysql/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'database-1',
                'version' => '8.4',
                'runtime' => 'docker-swarm',
                'status' => 'running',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['instance'])->toBe('mysql:8')
            ->and($decoded['data']['data']['tool']['runtime'])->toBe('docker-swarm');
    });

    it('uses the local default node for tool:install when no target is supplied', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-install-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'default-app', 'state' => 'installed'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'redis',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/redis/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'default-app',
                'status' => 'installed',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('validates tool:install status before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--status' => 'started',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('status')
            ->and($decoded['error']['meta']['reason'])->toBe('unsupported_value');
    });

    it('uses --json as destructive consent for tool:remove', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => ['name' => 'redis', 'node' => 'app-1', 'state' => 'removed'],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/tools/redis'
            && $request->data() === [
                'node' => 'app-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['tool']['state'])->toBe('removed');
    });

    it('prompts before removing a tool in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => ['name' => 'redis', 'node' => 'app-1', 'state' => 'removed'],
        ]));

        $this->artisan('tool:remove', [
            'tool' => 'redis',
            '--node' => 'app-1',
        ])
            ->expectsConfirmation("Remove tool 'redis'?", 'yes')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/tools/redis'
            && $request->data() === [
                'node' => 'app-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'interactive_confirm',
            ]);
    });

    it('requires force before tool:remove in non-json non-interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--no-interaction' => true,
        ]);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Use --force or --json to remove this tool.');
    });

    it('streams tool lifecycle actions to their gateway endpoints', function (string $command, string $endpoint): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'action' => $endpoint],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'tool' => 'redis',
            '--app' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "https://gateway.test/api/tools/redis/{$endpoint}"
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'app' => 'docs',
                'node' => 'app-1',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['action'])->toBe($endpoint);
    })->with([
        ['tool:start', 'start'],
        ['tool:stop', 'stop'],
        ['tool:restart', 'restart'],
        ['tool:reload', 'reload'],
    ]);

    it('streams explicit tool instance selectors for lifecycle actions', function (string $command, string $endpoint): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'mysql', 'node' => 'database-1', 'instance' => 'mysql:8', 'action' => $endpoint],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'tool' => 'mysql',
            '--node' => 'database-1',
            '--instance' => 'mysql:8',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "https://gateway.test/api/tools/mysql/{$endpoint}"
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'database-1',
                'instance' => 'mysql:8',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['instance'])->toBe('mysql:8');
    })->with([
        ['tool:start', 'start'],
        ['tool:stop', 'stop'],
        ['tool:restart', 'restart'],
        ['tool:reload', 'reload'],
    ]);

    it('streams tool:update payloads to the single-tool gateway endpoint', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'version' => '7.2'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--expected-version' => '7.2',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/redis/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'app-1',
                'version' => '7.2',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['version'])->toBe('7.2');
    });

    it('streams tool:update instance selectors to the single-tool gateway endpoint', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'mysql', 'node' => 'database-1', 'instance' => 'mysql:8', 'version' => '8.4'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'mysql',
            '--node' => 'database-1',
            '--instance' => 'mysql:8',
            '--expected-version' => '8.4',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/mysql/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'database-1',
                'instance' => 'mysql:8',
                'version' => '8.4',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['instance'])->toBe('mysql:8');
    });

    it('streams tool:update bulk payloads when the tool argument is omitted', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'updated' => [],
                'skipped' => [
                    ['tool' => 'redis', 'node' => 'app-1', 'reason' => 'null_latest_version'],
                ],
                'failed' => [],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === ['node' => 'app-1']);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['skipped'])->toHaveCount(1);
    });

    it('streams tool:reconfigure password payloads to the gateway', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'opencode-server', 'node' => 'app-1', 'action' => 'reconfigured'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:reconfigure', [
            'tool' => 'opencode-server',
            '--app' => 'docs',
            '--password' => 'newpass',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/opencode-server/reconfigure'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'app' => 'docs',
                'password' => 'newpass',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['action'])->toBe('reconfigured');
    });

    it('preserves gateway error envelopes for tool write commands', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope('tool.unsupported_action', "Tool 'docker' does not support install.", [
            'tool' => 'docker',
            'action' => 'install',
        ]), JSON_THROW_ON_ERROR), 422);

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'docker',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('tool.unsupported_action')
            ->and($decoded['error']['meta']['tool'])->toBe('docker');
    });
});
