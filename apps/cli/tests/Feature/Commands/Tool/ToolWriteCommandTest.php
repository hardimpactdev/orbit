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
                'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'installed'],
            ],
        ]));

        $this
            ->artisan('tool:install')
            ->expectsQuestion('Tool name', 'composer')
            ->expectsQuestion('Target node', 'app-1')
            ->expectsOutputToContain('composer')
            ->assertSuccessful();

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/install'
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => true,
                ]
            ),
        );
    });

    it('streams tool:install payloads to the gateway', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'running'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/install'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['tool']['state'])
            ->toBe('running');
    });

    it('streams host tool version intent without runtime fields', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => [
                    'name' => 'composer',
                    'node' => 'database-1',
                    'version' => '2.8',
                    'state' => 'installed',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'composer',
            '--node' => 'database-1',
            '--tool-version' => '2.8',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/install'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'database-1',
                    'version' => '2.8',
                    'with_process' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['tool'])
            ->not->toHaveKeys(['instance', 'version_family', 'runtime']);
    });

    it('uses the local default node for tool:install when no target is supplied', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-install-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'composer', 'node' => 'default-app', 'state' => 'installed'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'composer',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/install'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'default-app',
                    'with_process' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['tool']['node'])
            ->toBe('default-app');

        @unlink($store->path());
    });

    it('uses --json as destructive consent for tool:remove', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'removed'],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/tools/composer'
                && $request->data() === [
                    'node' => 'app-1',
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'json',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tool']['state'])
            ->toBe('removed')
            ->and($decoded['success']['meta'])
            ->toBeEmpty()
            ->and($output)
            ->toContain('"meta":[]')
            ->and($output)
            ->not->toContain('"meta":{}');
    });

    it('re-encodes empty gateway object meta as an empty array for tool:remove --json', function (): void {
        // ToolRemoveController returns 'meta' => (object) [], which JSON-encodes as {}.
        // GatewayApiClient json_decode(associative: true) turns that into [], and
        // EmitsCanonicalEnvelopes::renderSuccess re-emits JsonEnvelope success.meta as [].
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(\App\Services\GatewayApiClient::class);
        Http::fake([
            'https://gateway.test/*' => Http::response(
                '{"success":{"data":{"tool":{"name":"composer","node":"app-1","routes_removed":1}},"meta":{}}}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tool'])
            ->toBe([
                'name' => 'composer',
                'node' => 'app-1',
                'routes_removed' => 1,
            ])
            ->and($decoded['success']['meta'])
            ->toBeEmpty()
            ->and($output)
            ->toContain('"meta":[]')
            ->and($output)
            ->not->toContain('"meta":{}');
    });

    it('prompts before removing a tool in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'removed'],
        ]));

        $this
            ->artisan('tool:remove', [
                'tool' => 'composer',
                '--node' => 'app-1',
            ])
            ->expectsConfirmation("Remove tool 'composer'?", 'yes')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/tools/composer'
                && $request->data() === [
                    'node' => 'app-1',
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'interactive_confirm',
                ]
            ),
        );
    });

    it('requires force before tool:remove in non-json non-interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--no-interaction' => true,
        ]);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)->and($output)->toContain('Use --force or --json to remove this tool.');
    });

    it('streams tool:update payloads to the single-tool gateway endpoint', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'composer', 'node' => 'app-1', 'version' => '2.8'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--expected-version' => '2.8',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/update'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'app-1',
                    'version' => '2.8',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['tool']['version'])
            ->toBe('2.8');
    });

    it('streams tool:update bulk payloads when the tool argument is omitted', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'updated' => [],
                'skipped' => [
                    ['tool' => 'composer', 'node' => 'app-1', 'reason' => 'null_latest_version'],
                ],
                'failed' => [],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/update'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === ['node' => 'app-1']
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['skipped'])
            ->toHaveCount(1);
    });

    it('uses canonical json envelopes for lifecycle tool commands', function (string $command, string $action): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => [
                'name' => 'orbstack',
                'node' => 'mac-1',
                'action' => $action,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'tool' => 'orbstack',
            '--node' => 'mac-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === "https://gateway.test/api/tools/orbstack/{$action}"
                && $request->data() === ['node' => 'mac-1']
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toHaveKey('success')
            ->not
            ->toHaveKey('event')
            ->and($decoded['success']['data']['tool']['action'])
            ->toBe($action);
    })->with([
        'start' => ['tool:start', 'start'],
        'stop' => ['tool:stop', 'stop'],
        'restart' => ['tool:restart', 'restart'],
        'reload' => ['tool:reload', 'reload'],
    ]);

    it('streams lifecycle tool commands when requested', function (string $command, string $action): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => [
                    'name' => 'orbstack',
                    'node' => 'mac-1',
                    'action' => $action,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'tool' => 'orbstack',
            '--node' => 'mac-1',
            '--stream-json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === "https://gateway.test/api/tools/orbstack/{$action}"
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === ['node' => 'mac-1']
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['success']['data']['tool']['action'])
            ->toBe($action);
    })->with([
        'start' => ['tool:start', 'start'],
        'stop' => ['tool:stop', 'stop'],
        'restart' => ['tool:restart', 'restart'],
        'reload' => ['tool:reload', 'reload'],
    ]);

    it('reads tool logs through the capability-gated gateway endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'tool' => 'dns',
                'node' => 'gateway',
                'runtime' => 'tool',
                'lines' => [
                    ['message' => 'dns ready'],
                ],
            ],
        ], ['line_count' => 1]));

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'dns',
            '--node' => 'gateway',
            '--lines' => '25',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/tools/dns/logs?node=gateway&lines=25'
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['logs']['lines'][0]['message'])
            ->toBe('dns ready')
            ->and($decoded['success']['meta']['line_count'])
            ->toBe(1);
    });

    it('preserves lifecycle gateway error envelopes', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'tool.unsupported_action',
            "Tool 'composer' does not support start.",
            [
                'tool' => 'composer',
                'action' => 'start',
            ],
        ), 400);

        [$exitCode, $output] = runCommand($this, 'tool:start', [
            'tool' => 'composer',
            '--node' => 'mac-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/start'
                && $request->data() === ['node' => 'mac-1']
            ),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('tool.unsupported_action')
            ->and($decoded['error']['meta']['action'])
            ->toBe('start');
    });

    it('streams tool:reconfigure password payloads to the gateway', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'opencode-cli', 'node' => 'app-1', 'action' => 'reconfigured'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:reconfigure', [
            'tool' => 'opencode-cli',
            '--instance' => 'docs',
            '--password' => 'newpass',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/opencode-cli/reconfigure'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'instance' => 'docs',
                    'password' => 'newpass',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['tool']['action'])
            ->toBe('reconfigured');
    });

    it('passes unsupported install users to the gateway for catalog-owned validation', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope(
            'validation_failed',
            'Install users are only supported for user-scoped CLI tools.',
            [
                'field' => 'config.install_users',
                'value' => '',
                'reason' => 'unsupported_field',
            ],
        ), JSON_THROW_ON_ERROR), 422);

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--user' => ['agent'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/install'
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => true,
                    'config' => [
                        'install_users' => ['agent'],
                    ],
                ]
            ),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('config.install_users')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('unsupported_field');
    });

    it('forwards empty user values for claude-code so the gateway can reject them', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope(
            'validation_failed',
            "Invalid install user ''.",
            [
                'field' => 'config.install_users',
                'value' => '',
                'reason' => 'unsupported_value',
            ],
        ), JSON_THROW_ON_ERROR), 422);

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'claude-code',
            '--node' => 'app-1',
            '--user' => [''],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/claude-code/install'
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => true,
                    'config' => [
                        'install_users' => [''],
                    ],
                ]
            ),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('config.install_users');
    });

    it('forwards repeatable user options as user-scoped install config without runtime fields', function (string $tool): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => $tool, 'node' => 'app-1', 'state' => 'installed'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => $tool,
            '--node' => 'app-1',
            '--user' => ['agent', 'deploy'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === "https://gateway.test/api/tools/{$tool}/install"
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => true,
                    'config' => [
                        'install_users' => ['agent', 'deploy'],
                    ],
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data']['data']['tool'])
            ->not->toHaveKeys(['instance', 'version_family', 'runtime']);
    })->with([
        'claude code' => ['claude-code'],
        'codex cli' => ['codex-cli'],
    ]);

    it('preserves gateway error envelopes for tool write commands', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope(
            'tool.unsupported_action',
            "Tool 'docker' does not support install.",
            [
                'tool' => 'docker',
                'action' => 'install',
            ],
        ), JSON_THROW_ON_ERROR), 422);

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'docker',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('tool.unsupported_action')
            ->and($decoded['error']['meta']['tool'])
            ->toBe('docker');
    });
});
