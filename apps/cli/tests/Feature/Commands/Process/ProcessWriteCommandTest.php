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
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            '--restart-policy' => 'always',
            '--crash-notification' => 'agent_ide',
            '--runtime' => 'docker',
            '--start' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/processes'
            && $request->data() === [
                'app' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
                'restart_policy' => 'always',
                'crash_notification' => 'agent_ide',
                'start' => true,
                'runtime' => 'docker',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['process']['name'])->toBe('vite');
    });

    it('validates process:add input before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'Vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('validates process:add option enums before contacting the gateway', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'restart policy' => [['--restart-policy' => 'sometimes'], 'restart_policy'],
        'crash notification' => [['--crash-notification' => 'email'], 'crash_notification'],
        'runtime' => [['--runtime' => 'systemd'], 'runtime'],
    ]);

    it('patches process:edit payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'app' => 'docs', 'restart_policy' => 'on_failure'],
            'runtime_units' => [],
        ], [
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev -- --host 0.0.0.0',
            '--restart-policy' => 'on_failure',
            '--crash-notification' => 'none',
            '--runtime' => 'supervisor',
            '--restart' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://gateway.test/api/processes/vite'
            && $request->data() === [
                'app' => 'docs',
                'command' => 'npm run dev -- --host 0.0.0.0',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'runtime' => 'supervisor',
                'restart' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['process']['restart_policy'])->toBe('on_failure');
    });

    it('requires at least one process:edit field before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('editable_fields');
    });

    it('preserves gateway error envelopes for process:edit', function (): void {
        fakeGateway(fakeErrorEnvelope('process.not_found', "Process 'vite' not found.", [
            'name' => 'vite',
            'app' => 'docs',
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('process.not_found')
            ->and($decoded['error']['meta']['name'])->toBe('vite');
    });

    it('requires force before removing a process non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
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
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/processes/vite'
            && $request->data() === [
                'app' => 'docs',
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['process']['name'])->toBe('vite');
    });

    it('prompts before removing a process without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => ['name' => 'vite', 'app' => 'docs'],
            'runtime_units_removed' => [],
        ], [
            'warnings' => [],
        ]));

        $this->artisan('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
        ])
            ->expectsConfirmation("Remove process 'vite'?", 'yes')
            ->expectsOutputToContain('process')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/processes/vite');
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
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "https://gateway.test/api/processes/{$endpoint}"
            && $request->data() === [
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'name' => 'vite',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['runtimes'][0]['process'])->toBe('vite');
    })->with([
        ['process:start', 'start'],
        ['process:stop', 'stop'],
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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/processes/start'
            && $request->data() === [
                'workspace' => 'feature-docs',
                'name' => 'vite',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['runtimes'][0]['workspace'])->toBe('feature-docs');
    });

    it('requires an app or workspace context before process runtime actions contact the gateway', function (string $command): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'vite',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    })->with([
        'start' => 'process:start',
        'stop' => 'process:stop',
        'restart' => 'process:restart',
    ]);
});
