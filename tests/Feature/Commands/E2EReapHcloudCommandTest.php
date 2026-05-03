<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('lists stale hcloud e2e resources without deleting them by default', function (): void {
    Process::fake([
        'hcloud server list --selector orbit-e2e=true -o json' => Process::result(output: json_encode([
            ['id' => 101, 'name' => 'orbit-e2e-old-control', 'created' => '2026-05-03T05:00:00Z'],
            ['id' => 102, 'name' => 'orbit-e2e-fresh-control', 'created' => '2026-05-03T09:45:00Z'],
        ], JSON_THROW_ON_ERROR)),
        'hcloud ssh-key list --selector orbit-e2e=true -o json' => Process::result(output: json_encode([
            ['id' => 201, 'name' => 'orbit-e2e-old-key', 'created' => '2026-05-03T05:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-hcloud')
        ->expectsOutputToContain('Dry run. Pass --force to delete stale resources.')
        ->expectsOutputToContain('server 101 orbit-e2e-old-control')
        ->expectsOutputToContain('ssh-key 201 orbit-e2e-old-key')
        ->doesntExpectOutputToContain('orbit-e2e-fresh-control')
        ->assertSuccessful();

    Process::assertRan('hcloud server list --selector orbit-e2e=true -o json');
    Process::assertRan('hcloud ssh-key list --selector orbit-e2e=true -o json');
    Process::assertDidntRun('hcloud server delete 101');
    Process::assertDidntRun('hcloud ssh-key delete 201');
    Process::assertDidntRun('hcloud image list --selector orbit-e2e=true --type snapshot -o json');
});

it('deletes stale hcloud servers and ssh keys when forced', function (): void {
    Process::fake([
        'hcloud server list --selector orbit-e2e=true -o json' => Process::result(output: json_encode([
            ['id' => 101, 'name' => 'orbit-e2e-old-control', 'created' => '2026-05-03T05:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
        'hcloud ssh-key list --selector orbit-e2e=true -o json' => Process::result(output: json_encode([
            ['id' => 201, 'name' => 'orbit-e2e-old-key', 'created' => '2026-05-03T05:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
        'hcloud server delete 101' => Process::result(),
        'hcloud ssh-key delete 201' => Process::result(),
    ]);

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-hcloud', ['--force' => true])
        ->expectsOutputToContain('Deleted 2 stale hcloud E2E resources.')
        ->assertSuccessful();

    Process::assertRan('hcloud server delete 101');
    Process::assertRan('hcloud ssh-key delete 201');
});

it('only includes stale snapshots when snapshots are explicitly requested', function (): void {
    Process::fake([
        'hcloud server list --selector orbit-e2e=true -o json' => Process::result(output: '[]'),
        'hcloud ssh-key list --selector orbit-e2e=true -o json' => Process::result(output: '[]'),
        'hcloud image list --selector orbit-e2e=true --type snapshot -o json' => Process::result(output: json_encode([
            ['id' => 301, 'name' => 'orbit-e2e-old-snapshot', 'created' => '2026-05-03T05:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-hcloud', ['--snapshots' => true])
        ->expectsOutputToContain('image 301 orbit-e2e-old-snapshot')
        ->assertSuccessful();

    Process::assertRan('hcloud image list --selector orbit-e2e=true --type snapshot -o json');
    Process::assertDidntRun('hcloud image delete 301');
});

it('returns stale hcloud resources as json', function (): void {
    Process::fake([
        'hcloud server list --selector orbit-e2e=true -o json' => Process::result(output: json_encode([
            ['id' => 101, 'name' => 'orbit-e2e-old-control', 'created' => '2026-05-03T05:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
        'hcloud ssh-key list --selector orbit-e2e=true -o json' => Process::result(output: '[]'),
    ]);

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-hcloud', ['--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'hcloud',
                    'dry_run' => true,
                    'older_than_minutes' => 60,
                    'include_snapshots' => false,
                    'resources' => [
                        [
                            'type' => 'server',
                            'id' => '101',
                            'name' => 'orbit-e2e-old-control',
                            'created' => '2026-05-03T05:00:00Z',
                            'deleted' => false,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});
