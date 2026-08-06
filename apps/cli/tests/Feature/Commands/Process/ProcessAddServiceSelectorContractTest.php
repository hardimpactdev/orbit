<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('process:add managed service selector contract', function (): void {
    it('posts managed service payloads using service and version selectors with default start', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'mysql8',
                'node' => 'beast',
                'tool' => null,
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'orbit-mysql8', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'mysql8',
            '--node' => 'beast',
            '--service' => 'mysql',
            '--version' => '8.3',
            '--runtime' => 'docker',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'beast',
                    'name' => 'mysql8',
                    'restart_policy' => 'never',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'docker',
                    'service' => 'mysql',
                    'version' => '8.3',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['process']['name'])->toBe('mysql8');
    });

    it('posts typed PostgreSQL instance options with the public version selector', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'postgres-food',
                'node' => 'database1',
                'tool' => null,
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'orbit-postgres-food', 'context' => 'node']],
        ], ['warnings' => []]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'postgres-food',
            '--node' => 'database1',
            '--service' => 'postgres',
            '--version' => '18',
            '--database' => 'mealou_food_catalog',
            '--username' => 'mealou_food_catalog',
            '--published-port' => '5433',
            '--restart-policy' => 'always',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'database1',
                    'name' => 'postgres-food',
                    'restart_policy' => 'always',
                    'crash_notification' => 'none',
                    'start' => true,
                    'service' => 'postgres',
                    'version' => '18',
                    'service_options' => [
                        'database' => 'mealou_food_catalog',
                        'username' => 'mealou_food_catalog',
                        'published_port' => 5433,
                    ],
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('rejects missing and invalid PostgreSQL instance options before gateway IO', function (
        array $arguments,
        string $field,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'postgres-food',
            '--node' => 'database1',
            '--service' => 'postgres',
            '--version' => '18',
            '--json' => true,
            ...$arguments,
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
        'missing database' => [
            [
                '--username' => 'mealou_food_catalog',
                '--published-port' => '5433',
            ],
            'database',
        ],
        'invalid username' => [
            [
                '--database' => 'mealou_food_catalog',
                '--username' => 'Mealou User',
                '--published-port' => '5433',
            ],
            'username',
        ],
        'out of range port' => [
            [
                '--database' => 'mealou_food_catalog',
                '--username' => 'mealou_food_catalog',
                '--published-port' => '65536',
            ],
            'published_port',
        ],
    ]);

    it('posts a Valkey published-port override so a second instance can coexist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'valkey-feedback',
                'node' => 'nmbp',
                'tool' => null,
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'orbit-valkey-feedback', 'context' => 'node']],
        ], ['warnings' => []]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'valkey-feedback',
            '--node' => 'nmbp',
            '--service' => 'valkey',
            '--published-port' => '6380',
            '--restart-policy' => 'always',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data()['service'] === 'valkey'
                && $request->data()['service_options'] === ['published_port' => 6380]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('rejects PostgreSQL identifier options for non-postgres managed services', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'valkey-feedback',
            '--node' => 'nmbp',
            '--service' => 'valkey',
            '--database' => 'feedback',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('process_service_options_unsupported');
    });

    it('rejects a published-port override without a managed service selector', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'worker',
            'process_command' => 'php artisan queue:work',
            '--node' => 'nmbp',
            '--published-port' => '6380',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('process_service_options_unsupported');
    });

    it('posts optional image overrides for managed service processes', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'mysql8',
                'node' => 'beast',
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'orbit-mysql8', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'mysql8',
            '--node' => 'beast',
            '--service' => 'mysql',
            '--version' => '8.3',
            '--runtime' => 'docker',
            '--image' => 'docker.io/library/mysql:8.3',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'beast',
                    'name' => 'mysql8',
                    'restart_policy' => 'never',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'docker',
                    'service' => 'mysql',
                    'version' => '8.3',
                    'image' => 'docker.io/library/mysql:8.3',
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('posts explicit replacement containers with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'mailpit',
                'node' => 'beast',
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'mailpit', 'context' => 'node']],
            'replaced_containers' => ['dngdmt-mailpit-1', 'orbit-mailpit'],
        ], [
            'warnings' => [],
        ]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'mailpit',
            '--node' => 'beast',
            '--service' => 'mailpit',
            '--runtime' => 'docker',
            '--replace-container' => ['dngdmt-mailpit-1', 'orbit-mailpit'],
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'beast',
                    'name' => 'mailpit',
                    'restart_policy' => 'never',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'docker',
                    'service' => 'mailpit',
                    'replace_containers' => ['dngdmt-mailpit-1', 'orbit-mailpit'],
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('requires force before replacing containers non-interactively', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'mailpit',
            '--node' => 'beast',
            '--service' => 'mailpit',
            '--runtime' => 'docker',
            '--replace-container' => ['dngdmt-mailpit-1'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('destructive_consent_required');
    });

    it('sends start false only when no-start is present', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'mysql8',
                'node' => 'beast',
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'orbit-mysql8', 'context' => 'node']],
        ], [
            'warnings' => [],
        ]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'mysql8',
            '--node' => 'beast',
            '--service' => 'mysql',
            '--version' => '8.3',
            '--runtime' => 'docker',
            '--no-start' => true,
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST' && $request->data()['start'] === false,
        );

        expect($exitCode)->toBe(0);
    });

    it('rejects service versions without service before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'worker',
            'process_command' => 'php artisan queue:work',
            '--node' => 'beast',
            '--version' => '8.3',
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

    it('rejects image overrides without service before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'worker',
            'process_command' => 'php artisan queue:work',
            '--node' => 'beast',
            '--image' => 'docker.io/library/mysql:8.3',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('image')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('process_service_image_requires_service');
    });

    it('rejects image overrides for systemd managed services before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'node-exporter',
            '--node' => 'beast',
            '--service' => 'node-exporter',
            '--runtime' => 'systemd',
            '--image' => 'prom/node-exporter:v1.11.1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('image')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('process_service_image_requires_docker_runtime');
    });

    it('shows the start step by default in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'mysql8', 'node' => 'beast', 'app' => null, 'workspace' => null],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'mysql8',
            '--node' => 'beast',
            '--service' => 'mysql',
            '--version' => '8.3',
            '--runtime' => 'docker',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Start runtime units')
            ->and($output)
            ->toContain("Process 'mysql8' added for node 'beast'");
    });
});
