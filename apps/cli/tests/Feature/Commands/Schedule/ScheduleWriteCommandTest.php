<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('schedule write commands', function (): void {
    it('posts schedule:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'created', 'scheduler_pickup' => 'confirmed'],
            'schedule' => ['name' => 'laravel-scheduler', 'scope' => 'app'],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:add', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
            '--command' => 'php artisan schedule:run',
            '--interval' => 'every minute',
            '--timezone' => 'Europe/Amsterdam',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/schedules'
            && $request->data() === [
                'name' => 'laravel-scheduler',
                'app' => 'docs',
                'interval' => 'every minute',
                'timezone' => 'Europe/Amsterdam',
                'command' => 'php artisan schedule:run',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['schedule']['name'])->toBe('laravel-scheduler');
    });

    it('uses the local default node for schedule:add when no target is supplied', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-schedule-add-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'created', 'scheduler_pickup' => 'confirmed'],
            'schedule' => ['name' => 'nightly', 'scope' => 'node'],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:add', [
            'name' => 'nightly',
            '--script' => '/opt/orbit/nightly',
            '--interval' => 'daily at 02:00',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/schedules'
            && $request->data() === [
                'name' => 'nightly',
                'node' => 'default-app',
                'interval' => 'daily at 02:00',
                'timezone' => 'UTC',
                'script' => '/opt/orbit/nightly',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['schedule']['scope'])->toBe('node');

        @unlink($store->path());
    });

    it('validates schedule:add target and execution source conflicts before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$targetExitCode, $targetOutput] = runCommand($this, 'schedule:add', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--node' => 'app-1',
            '--command' => 'date',
            '--interval' => 'daily at 09:00',
            '--json' => true,
        ]);

        $targetDecoded = json_decode($targetOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        [$sourceExitCode, $sourceOutput] = runCommand($this, 'schedule:add', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--command' => 'date',
            '--script' => '/opt/orbit/nightly',
            '--interval' => 'daily at 09:00',
            '--json' => true,
        ]);

        $sourceDecoded = json_decode($sourceOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($targetExitCode)->toBe(1)
            ->and($targetDecoded['error']['code'])->toBe('validation_failed')
            ->and($targetDecoded['error']['meta']['fields'])->toBe(['app', 'node'])
            ->and($sourceExitCode)->toBe(1)
            ->and($sourceDecoded['error']['code'])->toBe('validation_failed')
            ->and($sourceDecoded['error']['meta']['fields'])->toBe(['command', 'script']);
    });

    it('validates schedule:add required fields before contacting the gateway', function (array $params, string $code, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:add', [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe($code)
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'name' => [
            [
                '--app' => 'docs',
                '--command' => 'date',
                '--interval' => 'daily at 09:00',
            ],
            'validation_failed',
            'name',
        ],
        'execution source' => [
            [
                'name' => 'nightly',
                '--app' => 'docs',
                '--interval' => 'daily at 09:00',
            ],
            'validation_failed',
            'execution_source',
        ],
        'interval' => [
            [
                'name' => 'nightly',
                '--app' => 'docs',
                '--command' => 'date',
            ],
            'schedule.interval_invalid',
            'interval',
        ],
        'timezone' => [
            [
                'name' => 'nightly',
                '--app' => 'docs',
                '--command' => 'date',
                '--interval' => 'daily at 09:00',
                '--timezone' => 'Moon/Base',
            ],
            'validation_failed',
            'timezone',
        ],
    ]);

    it('preserves gateway error envelopes for schedule:add', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.name_collision', "Schedule 'nightly' already exists.", [
            'name' => 'nightly',
            'app' => 'docs',
        ]), 409);

        [$exitCode, $output] = runCommand($this, 'schedule:add', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--command' => 'date',
            '--interval' => 'daily at 09:00',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('schedule.name_collision')
            ->and($decoded['error']['meta']['name'])->toBe('nightly');
    });

    it('requires force before removing a schedule non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'schedule:remove', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('destructive_consent_required')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes schedule:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedule' => ['name' => 'nightly', 'status' => 'removed'],
        ], [
            'scheduler_pickup' => 'confirmed',
            'history_retained' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:remove', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && str_starts_with($url, 'https://gateway.test/api/schedules/nightly')
                && str_contains($url, 'app=docs')
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['schedule']['status'])->toBe('removed');
    });

    it('prompts before removing a schedule without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedule' => ['name' => 'nightly', 'status' => 'removed'],
        ], [
            'scheduler_pickup' => 'confirmed',
            'history_retained' => true,
        ]));

        $this->artisan('schedule:remove', [
            'name' => 'nightly',
            '--app' => 'docs',
        ])
            ->expectsConfirmation("Remove schedule 'nightly'?", 'yes')
            ->expectsOutputToContain('schedule')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    });

    it('posts schedule:run requests to the gateway with scope filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'run' => ['id' => 18, 'schedule' => 'nightly', 'status' => 'completed'],
            'output' => ['stdout' => "ok\n", 'stderr' => ''],
        ], [
            'duration_ms' => 10,
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:run', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'POST'
                && str_starts_with($url, 'https://gateway.test/api/schedules/nightly/run')
                && str_contains($url, 'app=docs')
                && $request->data() === [];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['run']['id'])->toBe(18)
            ->and($decoded['success']['meta']['duration_ms'])->toBe(10);
    });

    it('preserves gateway error envelopes and data for schedule:run', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.run_failed', 'Schedule command failed.', [
            'name' => 'nightly',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 'schedule:run', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('schedule.run_failed')
            ->and($decoded['error']['meta']['name'])->toBe('nightly');
    });

    it('renders schedule:add human output as a progress tree with schedule detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'created', 'scheduler_pickup' => 'confirmed'],
            'schedule' => [
                'name' => 'laravel-scheduler',
                'scope' => 'app',
                'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                'interval' => 'every minute',
                'timezone' => 'Europe/Amsterdam',
                'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                'enabled' => true,
                'status' => 'expected',
                'last_run' => null,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:add', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
            '--command' => 'php artisan schedule:run',
            '--interval' => 'every minute',
            '--timezone' => 'Europe/Amsterdam',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Adding Schedule')
            ->and($output)->toContain('Notify Orbit Scheduler')
            ->and($output)->toContain("Schedule 'laravel-scheduler' added")
            ->and($output)->toContain('Name: laravel-scheduler')
            ->and($output)->toContain('Scope: app')
            ->and($output)->toContain('Target: app docs on app-1')
            ->and($output)->toContain('Interval: every minute')
            ->and($output)->toContain('Timezone: Europe/Amsterdam')
            ->and($output)->toContain('Execution: command php artisan schedule:run')
            ->and($output)->toContain('Enabled: true')
            ->and($output)->toContain('Scheduler pickup: confirmed')
            ->and($output)->not->toContain('{');
    });

    it('renders schedule:add gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.name_collision', "Schedule 'nightly' already exists."), 409);

        [$exitCode, $output] = runCommand($this, 'schedule:add', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--command' => 'date',
            '--interval' => 'daily at 09:00',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('already exists')
            ->and($output)->not->toContain('"error"');
    });

    it('renders schedule:remove human output as a progress tree with detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedule' => [
                'name' => 'nightly',
                'scope' => 'app',
                'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                'status' => 'removed',
            ],
        ], [
            'scheduler_pickup' => 'confirmed',
            'history_retained' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:remove', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Removing Schedule')
            ->and($output)->toContain('Apply and verify removal')
            ->and($output)->toContain("Schedule 'nightly' removed")
            ->and($output)->toContain('Name: nightly')
            ->and($output)->toContain('Scope: app')
            ->and($output)->toContain('Target: app docs on app-1')
            ->and($output)->toContain('Scheduler pickup: confirmed')
            ->and($output)->not->toContain('{');
    });

    it('renders schedule:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.target_unreachable', "Schedule 'nightly' was removed, but dispatch could not be confirmed."), 502);

        [$exitCode, $output] = runCommand($this, 'schedule:remove', [
            'name' => 'nightly',
            '--app' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('could not be confirmed')
            ->and($output)->not->toContain('"error"');
    });

    it('renders schedule:run human output as a progress tree with run detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'run' => [
                'id' => 18,
                'schedule' => 'nightly',
                'scope' => 'app',
                'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                'status' => 'completed',
                'exit_code' => 0,
                'started_at' => '2026-05-02T08:10:00Z',
                'finished_at' => '2026-05-02T08:10:03Z',
            ],
            'output' => ['stdout' => "scheduled task ran\n", 'stderr' => ''],
        ], [
            'duration_ms' => 3000,
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:run', [
            'name' => 'nightly',
            '--app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Running Schedule')
            ->and($output)->toContain('Record run history')
            ->and($output)->toContain("Schedule 'nightly' completed")
            ->and($output)->toContain('Run id: 18')
            ->and($output)->toContain('Status: completed')
            ->and($output)->toContain('Exit code: 0')
            ->and($output)->toContain('Started at: 2026-05-02T08:10:00Z')
            ->and($output)->toContain('Finished at: 2026-05-02T08:10:03Z')
            ->and($output)->toContain('scheduled task ran')
            ->and($output)->not->toContain('{');
    });

    it('renders schedule:run gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.run_failed', "Schedule 'nightly' exited with status 1."), 422);

        [$exitCode, $output] = runCommand($this, 'schedule:run', [
            'name' => 'nightly',
            '--app' => 'docs',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('exited with status 1')
            ->and($output)->not->toContain('"error"');
    });
});
