<?php

declare(strict_types=1);

use App\Console\Commands\E2EReapIncusCommand;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('is hidden', function (): void {
    $command = new E2EReapIncusCommand;

    expect($command->isHidden())->toBeTrue();
});

it('defaults to older-than=6h', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'incus',
                    'dry_run' => true,
                    'older_than_minutes' => 360,
                    'resources' => [
                        [
                            'type' => 'instance',
                            'id' => 'orbit-e2e-old',
                            'name' => 'orbit-e2e-old',
                            'created' => '2026-05-03T04:00:00Z',
                            'deleted' => false,
                            'host' => 'beast',
                        ],
                    ],
                    'skipped' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('parses 30m shorthand', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T09:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--older-than' => '30m', '--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'incus',
                    'dry_run' => true,
                    'older_than_minutes' => 30,
                    'resources' => [
                        [
                            'type' => 'instance',
                            'id' => 'orbit-e2e-old',
                            'name' => 'orbit-e2e-old',
                            'created' => '2026-05-03T09:00:00Z',
                            'deleted' => false,
                            'host' => 'beast',
                        ],
                    ],
                    'skipped' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('parses 2h shorthand', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T07:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--older-than' => '2h', '--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'incus',
                    'dry_run' => true,
                    'older_than_minutes' => 120,
                    'resources' => [
                        [
                            'type' => 'instance',
                            'id' => 'orbit-e2e-old',
                            'name' => 'orbit-e2e-old',
                            'created' => '2026-05-03T07:00:00Z',
                            'deleted' => false,
                            'host' => 'beast',
                        ],
                    ],
                    'skipped' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('parses 1d shorthand', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-02T09:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--older-than' => '1d', '--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'incus',
                    'dry_run' => true,
                    'older_than_minutes' => 1440,
                    'resources' => [
                        [
                            'type' => 'instance',
                            'id' => 'orbit-e2e-old',
                            'name' => 'orbit-e2e-old',
                            'created' => '2026-05-02T09:00:00Z',
                            'deleted' => false,
                            'host' => 'beast',
                        ],
                    ],
                    'skipped' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('lists stale instances without deleting them in dry run', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
                ['name' => 'orbit-e2e-fresh', 'created_at' => '2026-05-03T09:45:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus')
        ->expectsOutputToContain('Dry run. Pass --force to delete stale instances.')
        ->expectsOutputToContain('orbit-e2e-old')
        ->doesntExpectOutputToContain('orbit-e2e-fresh')
        ->assertSuccessful();

    Process::assertNotRan(function ($process) {
        return str_contains($process->command, 'incus delete');
    });
});

it('deletes stale instances when forced', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--force' => true])
        ->expectsOutputToContain('Deleted 1 stale Incus E2E instances.')
        ->assertSuccessful();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'incus delete');
    });
});

it('skips orbit-template and orbit-ready instances', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
                ['name' => 'orbit-template-control', 'created_at' => '2026-05-01T04:00:00Z'],
                ['name' => 'orbit-ready-gateway', 'created_at' => '2026-05-01T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus')
        ->expectsOutputToContain('orbit-e2e-old')
        ->expectsOutputToContain('Skipped protected names: orbit-template-control, orbit-ready-gateway')
        ->assertSuccessful();

    Process::assertNotRan(function ($process) {
        return str_contains($process->command, 'incus delete');
    });
});

it('returns structured json output', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'incus',
                    'dry_run' => true,
                    'older_than_minutes' => 360,
                    'resources' => [
                        [
                            'type' => 'instance',
                            'id' => 'orbit-e2e-old',
                            'name' => 'orbit-e2e-old',
                            'created' => '2026-05-03T04:00:00Z',
                            'deleted' => false,
                            'host' => 'beast',
                        ],
                    ],
                    'skipped' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});
