<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessesProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new ProcessesProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('processes');
        expect($this->probe->label())->toBe('Processes');
    });

    it('returns an empty foundation snapshot before live runtime probing is added', function (): void {
        $process = new Process(['name' => 'vite']);

        $snapshot = $this->probe->introspect($process);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('registry intent', function (): void {
    it('passes complete process records with eligible owner apps and runtime contexts', function (): void {
        $app = processableApp(['name' => 'docs']);
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature-docs',
                'path' => "{$app->path}/workspaces/feature-docs",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete process records', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => '',
            'restart_policy' => ProcessRestartPolicy::Never->value,
            'crash_notification' => ProcessCrashNotification::None->value,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('processes');
        expect($drift[0]->key)->toBe('process.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects unsupported restart policy intent', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'sometimes',
            'crash_notification' => ProcessCrashNotification::None->value,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects unsupported crash notification intent', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Never->value,
            'crash_notification' => 'pager',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });
});

describe('owner app eligibility', function (): void {
    it('requires an owner app on an active app node', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $app = App::factory()->for($node, 'node')->create();
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.owner_app_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway owner node' => [['role' => 'gateway', 'status' => 'active']],
        'inactive app owner node' => [['role' => 'app', 'status' => 'inactive']],
    ]);
});

describe('runtime context expansion', function (): void {
    it('detects runtime contexts that cannot produce safe supervisor program names', function (): void {
        $app = processableApp(['name' => 'Docs_App']);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.runtime_context_unresolved')?->kind)->toBe(DriftKind::Unverifiable);
    });

    it('detects invalid workspace runtime context identity', function (): void {
        $app = processableApp();
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'Feature_App',
                'path' => "{$app->path}/workspaces/feature",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.runtime_context_unresolved')?->kind)->toBe(DriftKind::Unverifiable);
    });
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

function processableApp(array $overrides = []): App
{
    $node = Node::factory()->create([
        'role' => 'app',
        'status' => 'active',
        'environment' => 'development',
    ]);

    return App::factory()
        ->for($node, 'node')
        ->create($overrides);
}

function processFor(App $app, array $overrides = []): Process
{
    return Process::factory()
        ->for($app, 'app')
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::None,
            'sort_order' => 1,
            ...$overrides,
        ]);
}
