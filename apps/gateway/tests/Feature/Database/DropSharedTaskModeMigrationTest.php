<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('drops worker_shared and strips the tasks section from Node settings, and rolls the column back', function (): void {
    $migration = require base_path('database/migrations/2026_11_02_000000_drop_shared_task_mode.php');
    $node = static fn (string $name, string $ip, ?array $settings): Node => Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
        'settings' => $settings,
    ]);
    $services = $node('services', '10.44.0.3', ['apps' => ['path' => '/srv/apps'], 'tasks' => ['worker' => 'none']]);
    $optedOut = $node('mini', '10.44.0.5', ['tasks' => ['worker' => 'none']]);
    $plain = $node('beast', '10.44.0.7', ['apps' => ['path' => '/fast/apps']]);
    $empty = $node('shark', '10.44.0.8', null);

    expect(Schema::hasColumn('instances', 'worker_shared'))->toBeFalse();

    $migration->down();
    expect(Schema::hasColumn('instances', 'worker_shared'))->toBeTrue();
    $columns = collect(DB::select('PRAGMA table_info(instances)'))->keyBy('name');
    expect((int) $columns['worker_shared']->notnull)->toBe(1)
        ->and(trim((string) $columns['worker_shared']->dflt_value, "'"))->toBe('0');

    $migration->up();

    expect(Schema::hasColumn('instances', 'worker_shared'))->toBeFalse()
        ->and($services->refresh()->settings)->toBe(['apps' => ['path' => '/srv/apps']])
        ->and($optedOut->refresh()->settings)->toBeNull()
        ->and($plain->refresh()->settings)->toBe(['apps' => ['path' => '/fast/apps']])
        ->and($empty->refresh()->settings)->toBeNull();
});
