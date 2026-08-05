<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('process write commands', function (): void {
    it('posts process:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'app' => 'docs'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            '--restart-policy' => 'always',
            '--crash-notification' => 'none',
            '--runtime' => 'systemd',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'instance' => 'docs',
                    'name' => 'vite',
                    'command' => 'npm run dev',
                    'restart_policy' => 'always',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'systemd',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['name'])->toBe('vite');
    });

    it('forwards an app-instance selector and preserves the concrete instance in JSON', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'vite',
                'app' => 'docs',
                'instance' => 'production',
            ],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs.production',
            '--runtime' => 'systemd',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data()['instance'] === 'docs.production'
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['process']['app'])
            ->toBe('docs')
            ->and($decoded['success']['data']['process']['instance'])
            ->toBe('production');
    });

    it('rejects app scoped docker process:add payloads before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'queue',
            'process_command' => 'php artisan queue:work',
            '--instance' => 'docs',
            '--runtime' => 'docker',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('runtime')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('docker_runtime_requires_service_or_managed_process');
    });

    it('posts node owned process:add payloads with tool dependencies to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'opencode-server',
                'node' => 'app-1',
                'tool' => 'opencode-cli',
                'runtime' => 'systemd',
            ],
            'runtime_units' => [['name' => 'opencode-server', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'opencode-server',
            'process_command' => 'opencode serve -a',
            '--node' => 'app-1',
            '--tool' => 'opencode-cli',
            '--runtime' => 'systemd',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'app-1',
                    'name' => 'opencode-server',
                    'command' => 'opencode serve -a',
                    'restart_policy' => 'never',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'systemd',
                    'tool' => 'opencode-cli',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['runtime'])->toBe('systemd');
    });

    it('posts node owned managed service process:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'mysql8',
                'node' => 'database-1',
                'tool' => null,
                'runtime' => 'docker-swarm',
            ],
            'runtime_units' => [['name' => 'orbit-mysql8', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'mysql8',
            '--node' => 'database-1',
            '--service' => 'mysql',
            '--version' => '8',
            '--runtime' => 'docker-swarm',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'database-1',
                    'name' => 'mysql8',
                    'restart_policy' => 'never',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'docker-swarm',
                    'service' => 'mysql',
                    'version' => '8',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['process']['tool'])
            ->toBeNull()
            ->and($decoded['success']['data']['process']['runtime'])
            ->toBe('docker-swarm');
    });

    it('rejects managed service tool dependencies before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'valkey',
            '--node' => 'database-1',
            '--service' => 'valkey',
            '--version' => '8',
            '--tool' => 'valkey',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('tool')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('process_service_cannot_reference_tool');
    });

    it('rejects service versions without service before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'worker',
            'process_command' => 'php artisan queue:work',
            '--node' => 'app-1',
            '--version' => '8',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('version')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('process_service_version_requires_service');
    });

    it('validates process:add input before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'Vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('name');
    });

    it('validates process:add option enums before contacting the gateway', function (
        array $params,
        string $field,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe($field);
    })->with([
        'restart policy' => [['--restart-policy' => 'sometimes'], 'restart_policy'],
        'crash notification' => [['--crash-notification' => 'email'], 'crash_notification'],
        'runtime' => [['--runtime' => 'podman'], 'runtime'],
    ]);

    it('forwards process:add --label and omits label when not supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'key' => 'vite', 'label' => 'Vite Dev Server'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            '--label' => 'Vite Dev Server',
            '--runtime' => 'systemd',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->data()['label'] === 'Vite Dev Server'
                && $request->data()['name'] === 'vite'
            ),
        );

        expect($exitCode)->toBe(0);

        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'queue', 'key' => 'queue', 'label' => 'queue'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        runCommand($this, 'process:add', [
            'name' => 'queue',
            'process_command' => 'php artisan queue:work',
            '--instance' => 'docs',
            '--runtime' => 'systemd',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/processes'
            && $request->data()['name'] === 'queue'
            && ! array_key_exists('label', $request->data()),
        );
    });

    it('rejects explicit empty process:add --label= before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            '--label' => '',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('label');
    });

    it('patches process:update payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'app' => 'docs', 'restart_policy' => 'on_failure'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--command' => 'npm run dev -- --host 0.0.0.0',
            '--restart-policy' => 'on_failure',
            '--crash-notification' => 'none',
            '--runtime' => 'systemd',
            '--restart' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PATCH'
                && $request->url() === 'https://gateway.test/api/processes/vite'
                && $request->data() === [
                    'instance' => 'docs',
                    'command' => 'npm run dev -- --host 0.0.0.0',
                    'restart_policy' => 'on_failure',
                    'crash_notification' => 'none',
                    'runtime' => 'systemd',
                    'restart' => true,
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['restart_policy'])->toBe('on_failure');
    });

    it('forwards process:update --label and rejects explicit empty --label=', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'key' => 'vite', 'label' => 'Vite Dev Server'],
            'changed' => ['label'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--label' => 'Vite Dev Server',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PATCH'
                && $request->url() === 'https://gateway.test/api/processes/vite'
                && $request->data() === [
                    'instance' => 'docs',
                    'label' => 'Vite Dev Server',
                    'restart' => false,
                ]
            ),
        );

        expect($exitCode)->toBe(0);

        Http::fake();

        [$emptyCode, $emptyOutput] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--label' => '',
            '--json' => true,
        ]);

        $decoded = json_decode($emptyOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($emptyCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('label');
    });

    it('patches process:update rename payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'app-mysql', 'node' => 'database-1', 'runtime' => 'docker'],
            'old_name' => 'mysql',
            'changed' => ['name'],
            'runtime_units' => [['name' => 'orbit_node_database_1_app_mysql', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'process:update', params: [
            'name' => 'mysql',
            '--node' => 'database-1',
            '--name' => 'app-mysql',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PATCH'
                && $request->url() === 'https://gateway.test/api/processes/mysql'
                && $request->data() === [
                    'node' => 'database-1',
                    'name' => 'app-mysql',
                    'restart' => false,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['process']['name'])
            ->toBe('app-mysql')
            ->and($decoded['success']['data']['old_name'])
            ->toBe('mysql');
    });

    it('rejects app scoped docker process:update payloads before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'queue',
            '--instance' => 'docs',
            '--runtime' => 'docker',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('runtime')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('docker_runtime_requires_service_or_managed_process');
    });

    it('patches node owned process:update payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'opencode-server', 'node' => 'app-1', 'runtime' => 'systemd'],
            'runtime_units' => [['name' => 'opencode-server', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'opencode-server',
            '--node' => 'app-1',
            '--command' => 'opencode serve -a',
            '--runtime' => 'systemd',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PATCH'
                && $request->url() === 'https://gateway.test/api/processes/opencode-server'
                && $request->data() === [
                    'node' => 'app-1',
                    'command' => 'opencode serve -a',
                    'runtime' => 'systemd',
                    'restart' => false,
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['runtime'])->toBe('systemd');
    });

    it('requires at least one process:update field before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('editable_fields');
    });

    it('preserves gateway error envelopes for process:update', function (): void {
        fakeGateway(fakeErrorEnvelope('process.not_found', "Process 'vite' not found.", [
            'name' => 'vite',
            'app' => 'docs',
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--command' => 'npm run dev',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('process.not_found')
            ->and($decoded['error']['meta']['name'])
            ->toBe('vite');
    });

    it('requires force before removing a process non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'process:remove', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force');
    });

    it('deletes process:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'app' => 'docs'],
            'runtime_units_removed' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:remove', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/processes/vite'
                && $request->data() === [
                    'instance' => 'docs',
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['name'])->toBe('vite');
    });

    it('deletes workspace owned process:remove payloads with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'worker', 'app' => 'docs', 'workspace' => 'feature-docs'],
            'runtime_units_removed' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:remove', [
            'name' => 'worker',
            '--instance' => 'docs',
            '--workspace' => 'feature-docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/processes/worker'
                && $request->data() === [
                    'instance' => 'docs',
                    'workspace' => 'feature-docs',
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['workspace'])->toBe('feature-docs');
    });

    it('prompts before removing a process without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'app' => 'docs'],
            'runtime_units_removed' => [],
        ], [
            'warnings' => [],
        ]));

        $this
            ->artisan('process:remove', [
                'name' => 'vite',
                '--instance' => 'docs',
            ])
            ->expectsConfirmation("Remove process 'vite'?", 'yes')
            ->expectsOutputToContain('process')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/processes/vite'
            ),
        );
    });

    it('posts process runtime actions to their gateway endpoints', function (string $command, string $endpoint): void {
        fakeGateway(fakeSuccessEnvelope([
            'runtimes' => [
                [
                    'process' => 'vite',
                    'app' => 'docs',
                    'workspace' => 'feature-docs',
                    'status' => 'ok',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'vite',
            '--instance' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === "https://gateway.test/api/processes/{$endpoint}"
                && $request->data() === [
                    'instance' => 'docs',
                    'workspace' => 'feature-docs',
                    'name' => 'vite',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['runtimes'][0]['process'])->toBe('vite');
    })->with([
        ['process:start',   'start'],
        ['process:stop',    'stop'],
        ['process:restart', 'restart'],
    ]);

    it('posts process runtime actions with a workspace-only context', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runtimes' => [
                [
                    'process' => 'vite',
                    'workspace' => 'feature-docs',
                    'status' => 'ok',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:start', [
            'name' => 'vite',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes/start'
                && $request->data() === [
                    'workspace' => 'feature-docs',
                    'name' => 'vite',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['runtimes'][0]['workspace'])->toBe('feature-docs');
    });

    it('posts process runtime actions with a node context', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runtimes' => [
                [
                    'process' => 'opencode-server',
                    'node' => 'app-1',
                    'runtime_unit' => 'opencode-server',
                    'status' => 'ok',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:start', [
            'name' => 'opencode-server',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes/start'
                && $request->data() === [
                    'node' => 'app-1',
                    'name' => 'opencode-server',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['runtimes'][0]['node'])->toBe('app-1');
    });

    it('posts process runtime actions with an app hostname selector', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runtimes' => [
                [
                    'process' => 'vite',
                    'instance' => 'development',
                    'status' => 'ok',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:restart', [
            'name' => 'vite',
            '--app' => 'test.app.example',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes/restart'
                && $request->data() === [
                    'app' => 'test.app.example',
                    'name' => 'vite',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['runtimes'][0]['process'])->toBe('vite');
    });

    it('requires an instance, workspace, node, or app context before process runtime actions contact the gateway', function (string $command): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'vite',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('instance');
    })->with([
        'start' => 'process:start',
        'stop' => 'process:stop',
        'restart' => 'process:restart',
    ]);

    it('renders process:add human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'vite',
                'node' => 'app-1',
                'app' => 'docs',
                'instance' => 'production',
                'workspace' => null,
            ],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs.production',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Adding Process')
            ->and($output)
            ->toContain('Create process configuration')
            ->and($output)
            ->toContain('Start runtime units')
            ->and($output)
            ->toContain("Process 'vite' added for instance 'docs.production'")
            ->and($output)
            ->not->toContain('process:')->and($output)
            ->not->toContain('{');
    });

    it('omits the process:add start step when --no-start is present', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'node' => 'app-1', 'app' => 'docs', 'workspace' => null],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            '--runtime' => 'systemd',
            '--no-start' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Adding Process')
            ->and($output)
            ->not
            ->toContain('Start runtime units')
            ->and($output)
            ->toContain("Process 'vite' added for instance 'docs'");
    });

    it('shows the process:add start step by default', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'node' => 'app-1', 'app' => 'docs', 'workspace' => null],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
            '--runtime' => 'systemd',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Adding Process')
            ->and($output)
            ->toContain('Start runtime units')
            ->and($output)
            ->toContain("Process 'vite' added for instance 'docs'");
    });

    it('does not expose the removed redundant process:add start option', function (): void {
        $command = app(\Illuminate\Contracts\Console\Kernel::class)->all()['process:add'];

        expect($command->getDefinition()->hasOption('start'))->toBeFalse();
    });

    it('renders process:add runtime drift as a success footer with a drift note', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'node' => 'app-1', 'app' => 'docs', 'workspace' => null],
            'runtime_units' => [],
        ], [
            'warnings' => [
                ['code' => 'process.runtime_unit_apply_failed', 'message' => 'process.runtime_unit_missing'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Process 'vite' added for instance 'docs'")
            ->and($output)
            ->toContain('Drift detected: process.runtime_unit_missing');
    });

    it('renders process:add gateway failures as prose in human mode', function (): void {
        fakeGateway(
            fakeErrorEnvelope('authorization_failed', "This node is not authorized for 'process:add' on 'app-1'."),
            403,
        );

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('not authorized')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('keeps process:add validation failures as prose before the tree', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'Vite',
            'process_command' => 'npm run dev',
            '--instance' => 'docs',
        ]);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->not
            ->toContain('Adding Process')
            ->and($output)
            ->toContain('validation_failed');
    });

    it('renders process:update human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'node' => 'app-1', 'app' => 'docs', 'workspace' => null],
            'changed' => ['command'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--command' => 'npm run dev -- --host 0.0.0.0',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Updating Process')
            ->and($output)
            ->toContain('Apply and verify process change')
            ->and($output)
            ->not->toContain('Restart runtime units')->and($output)->toContain(
                "Process 'vite' updated for instance 'docs'",
            )->and($output)
            ->not->toContain('process:')->and($output)
            ->not->toContain('{');
    });

    it('shows the process:update restart step only when --restart is present', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'node' => 'app-1', 'app' => 'docs', 'workspace' => null],
            'changed' => ['command'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--command' => 'npm run dev',
            '--restart' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Restart runtime units')
            ->and($output)
            ->toContain("Process 'vite' updated for instance 'docs'");
    });

    it('renders process:update gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('process.not_found', "Process 'vite' not found for instance 'docs'."), 404);

        [$exitCode, $output] = runCommand($this, 'process:update', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--command' => 'npm run dev',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('not found')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders process:remove human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'node' => 'app-1', 'app' => 'docs', 'workspace' => null],
            'removed_runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:remove', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Removing Process')
            ->and($output)
            ->toContain('Apply and verify process removal')
            ->and($output)
            ->toContain("Process 'vite' removed from instance 'docs'")
            ->and($output)
            ->not->toContain('process:')->and($output)
            ->not->toContain('{');
    });

    it('keeps the process:remove cancelled-confirmation prose before the tree', function (): void {
        Http::fake();

        $this
            ->artisan('process:remove', [
                'name' => 'vite',
                '--instance' => 'docs',
            ])
            ->expectsConfirmation("Remove process 'vite'?", 'no')
            ->doesntExpectOutputToContain('Removing Process')
            ->doesntExpectOutputToContain('{')
            ->assertExitCode(1);

        Http::assertNothingSent();
    });

    it('renders process:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('process.not_found', "Process 'vite' not found for instance 'docs'."), 404);

        [$exitCode, $output] = runCommand($this, 'process:remove', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('not found')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders named process runtime actions as a progress tree with a success footer', function (
        string $command,
        string $title,
        string $pastTense,
    ): void {
        fakeGateway(fakeSuccessEnvelope([
            'runtimes' => [
                [
                    'process' => 'vite',
                    'node' => 'app-1',
                    'app' => 'docs',
                    'workspace' => null,
                    'state' => 'running',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'vite',
            '--instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain($title)
            ->and($output)
            ->toContain('Resolve runtime units')
            ->and($output)
            ->toContain("Process 'vite' {$pastTense} for instance 'docs'")
            ->and($output)
            ->not->toContain('runtimes:')->and($output)
            ->not->toContain('{');
    })->with([
        ['process:start',   'Starting Processes',   'started'],
        ['process:stop',    'Stopping Processes',   'stopped'],
        ['process:restart', 'Restarting Processes', 'restarted'],
    ]);

    it('renders bulk process runtime actions with a counted success footer', function (
        string $command,
        string $pastTense,
    ): void {
        fakeGateway(fakeSuccessEnvelope([
            'runtimes' => [
                [
                    'process' => 'vite',
                    'node' => 'app-1',
                    'app' => null,
                    'workspace' => 'feature-docs',
                    'state' => 'running',
                ],
                [
                    'process' => 'worker',
                    'node' => 'app-1',
                    'app' => null,
                    'workspace' => 'feature-docs',
                    'state' => 'running',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            '--workspace' => 'feature-docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("2 processes {$pastTense} for workspace 'feature-docs'")
            ->and($output)
            ->not->toContain('{');
    })->with([
        ['process:start',   'started'],
        ['process:stop',    'stopped'],
        ['process:restart', 'restarted'],
    ]);

    it('renders process runtime action gateway failures as prose in human mode', function (string $command): void {
        fakeGateway(
            fakeErrorEnvelope('process.runtime_action_failed', "The runtime unit could not be {$command}."),
            422,
        );

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'vite',
            '--instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('runtime unit could not be')
            ->and($output)
            ->not->toContain('"error"');
    })->with([
        'start' => 'process:start',
        'stop' => 'process:stop',
        'restart' => 'process:restart',
    ]);

    it('keeps process runtime action validation failures as prose before the tree', function (string $command): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'vite',
        ]);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->not
            ->toContain('Processes')
            ->and($output)
            ->toContain('validation_failed');
    })->with([
        'start' => 'process:start',
        'stop' => 'process:stop',
        'restart' => 'process:restart',
    ]);
});
