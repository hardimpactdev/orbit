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

        $this->artisan('tool:install')
            ->expectsQuestion('Tool name', 'composer')
            ->expectsQuestion('Target node', 'app-1')
            ->expectsOutputToContain('composer')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/composer/install'
            && $request->data() === [
                'node' => 'app-1',
                'status' => 'installed',
                'with_process' => true,
            ]);
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
            '--status' => 'running',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/composer/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'app-1',
                'status' => 'running',
                'with_process' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['state'])->toBe('running');
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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/composer/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'database-1',
                'version' => '2.8',
                'status' => 'installed',
                'with_process' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool'])->not->toHaveKeys(['instance', 'version_family', 'runtime']);
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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/composer/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'default-app',
                'status' => 'installed',
                'with_process' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('validates tool:install status before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'composer',
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
            'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'removed'],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/tools/composer'
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
            'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'removed'],
        ]));

        $this->artisan('tool:remove', [
            'tool' => 'composer',
            '--node' => 'app-1',
        ])
            ->expectsConfirmation("Remove tool 'composer'?", 'yes')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/tools/composer'
            && $request->data() === [
                'node' => 'app-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'interactive_confirm',
            ]);
    });

    it('requires force before tool:remove in non-json non-interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'tool:remove', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--no-interaction' => true,
        ]);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Use --force or --json to remove this tool.');
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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/composer/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'app-1',
                'version' => '2.8',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['tool']['version'])->toBe('2.8');
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
