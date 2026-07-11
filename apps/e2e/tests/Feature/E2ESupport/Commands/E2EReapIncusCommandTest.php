<?php

declare(strict_types=1);

use App\Console\Commands\E2EReapIncusCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('is hidden', function (): void {
    $command = new E2EReapIncusCommand;

    expect($command->isHidden())->toBeTrue();
});

it('defaults to older-than=30m', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this
        ->artisan('e2e:reap-incus', ['--json' => true])
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

    $this
        ->artisan('e2e:reap-incus', ['--older-than' => '30m', '--json' => true])
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

    $this
        ->artisan('e2e:reap-incus', ['--older-than' => '2h', '--json' => true])
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

    $this
        ->artisan('e2e:reap-incus', ['--older-than' => '1d', '--json' => true])
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

it('rejects unsupported cleanup scopes before listing Incus resources', function (): void {
    Process::fake();

    $this
        ->artisan('e2e:reap-incus', ['--scope' => 'instances,volumes'])
        ->expectsOutputToContain('Unsupported --scope value(s): volumes.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('rejects negative older-than values before listing Incus resources', function (): void {
    Process::fake();

    $this
        ->artisan('e2e:reap-incus', ['--older-than' => '-1'])
        ->expectsOutputToContain('Invalid --older-than format: -1')
        ->assertFailed();

    Process::assertNothingRan();
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

    $this
        ->artisan('e2e:reap-incus')
        ->expectsOutputToContain('Dry run. Pass --force to delete stale instances.')
        ->expectsOutputToContain('orbit-e2e-old')
        ->doesntExpectOutputToContain('orbit-e2e-fresh')
        ->assertSuccessful();

    Process::assertNotRan(function ($process) {
        return str_contains($process->command, 'incus delete');
    });
});

it('keeps non-instance Incus cleanup as an explicit opt-in scope', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'incus list --format json')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result(output: '[]');
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-incus', ['--json' => true])
        ->assertSuccessful();

    expect(implode("\n", $commands))
        ->toContain('incus list --format json')
        ->not->toContain('incus network list --format json')
        ->not->toContain('incus image list --format json')
        ->not->toContain('find /tmp');
});

it('runs Incus inventory locally for localhost Incus hosts', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'incus list --format json')) {
            return Process::result(output: json_encode([], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    withE2EEnvironment(
        [],
        [
            'ORBIT_E2E_INCUS_HOSTS' => 'localhost',
        ],
        function (): void {
            $this->artisan('e2e:reap-incus', ['--json' => true])
                ->assertSuccessful();
        },
    );

    expect($commands[0])
        ->toContain('bash -lc')
        ->toContain('incus list --format json')
        ->not->toContain('ssh -o BatchMode=yes');
});

it('inventories stale networks templates images and tmp artifacts when those scopes are requested', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus network list --format json')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-n-1', 'used_by' => []],
                ['name' => 'orbit-e2e-n-2', 'used_by' => ['/1.0/instances/orbit-e2e-active']],
                ['name' => 'other-n-1', 'used_by' => []],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($process->command, 'incus image list --format json')) {
            return Process::result(output: json_encode([
                [
                    'aliases' => [['name' => 'orbit-blank-ubuntu-26.04']],
                    'created_at' => '2026-05-01T00:00:00Z',
                    'expires_at' => '2026-05-31T00:00:00Z',
                    'last_used_at' => '2026-05-26T00:00:00Z',
                    'size' => 123,
                ],
                [
                    'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
                    'created_at' => '2026-05-01T00:00:00Z',
                    'expires_at' => '2026-05-31T00:00:00Z',
                    'last_used_at' => '2026-05-26T00:00:00Z',
                    'size' => 456,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($process->command, 'find /tmp')) {
            return Process::result(output: implode("\n", [
                '/tmp/orbit-e2e-docker-image-export-old	2026-05-01T00:00:00+0000	1024',
                '/tmp/orbit-e2e-sources/old-worktree	2026-05-01T00:00:00+0000	2048',
                '/tmp/orbit-e2e-sources/current-worktree/retained/dev-old123	2026-05-01T00:00:00+0000	4096',
                '/tmp/orbit-e2e-source-locks	2026-05-01T00:00:00+0000	4096',
                '',
            ]));
        }

        if (str_contains($process->command, 'incus list --format json')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
                ['name' => 'orbit-template-operator-base', 'created_at' => '2026-05-01T04:00:00Z'],
                ['name' => 'orbit-template-agent-ab-cloud-current', 'created_at' => '2026-05-01T04:00:00Z'],
                [
                    'name' => 'orbit-template-prepared-agent',
                    'created_at' => '2026-05-01T04:00:00Z',
                    'snapshots' => [
                        ['name' => 'clean-prepared-agent'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-06-21T10:00:00Z'));

    $exitCode = Artisan::call('e2e:reap-incus', [
        '--scope' => 'instances,networks,templates,images,tmp',
        '--json' => true,
    ]);

    $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    $resources = collect($payload['success']['data']['resources'])
        ->keyBy(fn (array $resource): string => "{$resource['type']}:{$resource['name']}");

    expect($exitCode)
        ->toBe(0)
        ->and($resources->keys()->all())
        ->toContain(
            'instance:orbit-e2e-old',
            'network:orbit-e2e-n-1',
            'template:orbit-template-prepared-agent',
            'image:orbit-blank-ubuntu-26.04',
            'tmp_path:/tmp/orbit-e2e-docker-image-export-old',
        )
        ->not->toContain(
            'tmp_path:/tmp/orbit-e2e-sources/old-worktree',
            'tmp_path:/tmp/orbit-e2e-sources/current-worktree/retained/dev-old123',
            'tmp_path:/tmp/orbit-e2e-source-locks',
            'network:orbit-e2e-n-2',
            'network:other-n-1',
            'template:orbit-template-operator-base',
            'template:orbit-template-agent-ab-cloud-current',
            'image:orbit-base-ubuntu-26.04-runtime',
        );

    expect($resources->get('template:orbit-template-prepared-agent')['snapshots'])
        ->toBe(['clean-prepared-agent']);

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'incus network delete'));
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'incus image delete'));
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'rm -rf --'));
    Process::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'find /tmp') && str_contains($process->command, 'orbit-e2e-source-locks')
        ),
    );
});

it('deletes only explicitly scoped stale Incus artifacts when forced', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus network list --format json')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-n-1', 'used_by' => []],
                ['name' => 'orbit-e2e-n-2', 'used_by' => ['/1.0/instances/orbit-e2e-active']],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($process->command, 'incus image list --format json')) {
            return Process::result(output: json_encode([
                [
                    'aliases' => [['name' => 'orbit-blank-ubuntu-26.04']],
                    'created_at' => '2026-05-01T00:00:00Z',
                    'expires_at' => '2026-05-31T00:00:00Z',
                    'last_used_at' => '2026-05-26T00:00:00Z',
                    'size' => 123,
                ],
                [
                    'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
                    'created_at' => '2026-05-01T00:00:00Z',
                    'expires_at' => '2026-05-31T00:00:00Z',
                    'last_used_at' => '2026-05-26T00:00:00Z',
                    'size' => 456,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($process->command, 'find /tmp')) {
            return Process::result(output: "/tmp/orbit-e2e-docker-image-export-old\t2026-05-01T00:00:00+0000\t1024\n");
        }

        if (str_contains($process->command, 'incus list --format json')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-template-operator-base', 'created_at' => '2026-05-01T04:00:00Z'],
                ['name' => 'orbit-template-prepared-agent', 'created_at' => '2026-05-01T04:00:00Z'],
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-06-21T10:00:00Z'));

    $this->artisan('e2e:reap-incus', [
        '--scope' => 'networks,templates,images,tmp',
        '--force' => true,
    ])->assertSuccessful();

    Process::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'incus network delete') && str_contains($process->command, 'orbit-e2e-n-1')
        ),
    );
    Process::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'incus delete --force')
            && str_contains($process->command, 'orbit-template-prepared-agent')
        ),
    );
    Process::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'incus image delete')
            && str_contains($process->command, 'orbit-blank-ubuntu-26.04')
        ),
    );
    Process::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'rm -rf --')
            && str_contains($process->command, '/tmp/orbit-e2e-docker-image-export-old')
        ),
    );

    Process::assertNotRan(
        fn ($process): bool => (
            str_contains($process->command, 'incus network delete') && str_contains($process->command, 'orbit-e2e-n-2')
        ),
    );
    Process::assertNotRan(
        fn ($process): bool => (
            str_contains($process->command, 'incus delete --force')
            && str_contains($process->command, 'orbit-template-operator-base')
        ),
    );
    Process::assertNotRan(
        fn ($process): bool => (
            str_contains($process->command, 'incus image delete')
            && str_contains($process->command, 'orbit-base-ubuntu-26.04-runtime')
        ),
    );
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

    $this
        ->artisan('e2e:reap-incus', ['--force' => true])
        ->expectsOutputToContain('Deleted 1 stale Incus E2E instances.')
        ->assertSuccessful();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'incus delete');
    });
});

it('only reaps instances matching the configured runtime prefix', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
                ['name' => 'orbit-e2e-ab-current-old', 'created_at' => '2026-05-03T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    withE2EEnvironment(
        [],
        [
            'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-e2e-ab-current',
        ],
        function (): void {
            $this
                ->artisan('e2e:reap-incus', ['--force' => true])
                ->expectsOutputToContain('orbit-e2e-ab-current-old')
                ->doesntExpectOutputToContain('orbit-e2e-old')
                ->assertSuccessful();
        },
    );

    Process::assertRan(function ($process) {
        return (
            str_contains($process->command, 'incus delete --force')
            && str_contains($process->command, 'orbit-e2e-ab-current-old')
        );
    });

    Process::assertNotRan(function ($process) {
        return (
            str_contains($process->command, 'incus delete --force') && str_contains($process->command, 'orbit-e2e-old')
        );
    });
});

it('skips orbit-template and orbit-ready instances', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'incus list')) {
            return Process::result(output: json_encode([
                ['name' => 'orbit-e2e-old', 'created_at' => '2026-05-03T04:00:00Z'],
                ['name' => 'orbit-template-operator', 'created_at' => '2026-05-01T04:00:00Z'],
                ['name' => 'orbit-ready-gateway', 'created_at' => '2026-05-01T04:00:00Z'],
            ]));
        }

        return Process::result();
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this
        ->artisan('e2e:reap-incus')
        ->expectsOutputToContain('orbit-e2e-old')
        ->expectsOutputToContain('Skipped protected names: orbit-template-operator, orbit-ready-gateway')
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

    $this
        ->artisan('e2e:reap-incus', ['--json' => true])
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
