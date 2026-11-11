<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Build the real schema once; each dataset gets a disposable copy with committed fixtures. */
function slotConcurrencySchema(): string
{
    static $schema = null;
    if ($schema === null) {
        expect(Artisan::call('migrate:fresh'))->toBe(0);
        $schema = tempnam(sys_get_temp_dir(), 'orbit-slot-schema-');
        unlink($schema);
        DB::statement('VACUUM INTO ?', [$schema]);
        register_shutdown_function(static fn (): bool => unlink($schema));
    }

    return $schema;
}

beforeEach(function (): void {
    $schema = slotConcurrencySchema();
    $file = tempnam(sys_get_temp_dir(), 'orbit-slot-fixture-');
    $this->beforeApplicationDestroyed(static function () use ($file): void {
        DB::purge();
        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
    copy($schema, $file);
    DB::purge();
    config(['database.connections.sqlite.database' => $file]);
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/** Interleaves two independent SQLite connections at the admission lock, then runs the real allocator on each. */
function interleavedSlotClaims(array $claims): array
{
    $file = tempnam(sys_get_temp_dir(), 'orbit-slot-lock-');
    unlink($file);
    DB::statement('VACUUM INTO ?', [$file]);
    $default = DB::getDefaultConnection();
    $connections = ['slot-holder', 'slot-contender'];
    foreach ($connections as $name) {
        config(["database.connections.{$name}" => [...config("database.connections.{$default}"), 'database' => $file, 'busy_timeout' => 0]]);
    }
    try {
        [$holder, $contender] = array_map(DB::connection(...), $connections);
        expect($holder->getPdo())->not->toBe($contender->getPdo());
        $holder->beginTransaction();
        try {
            $contender->beginTransaction();
            $contender->rollBack();
            throw new RuntimeException('Expected the other connection to hold the admission lock.');
        } catch (PDOException $error) {
            expect($error->getMessage())->toContain('locked');
        }

        // The failed lock attempt is the contention barrier. Commit the holder before admitting the contender;
        // neither a child process nor SQLite's busy handler needs to win a scheduling race to make progress.
        $results = [];
        foreach ($claims as $index => [$group, $host, $ci]) {
            DB::setDefaultConnection($connections[$index]);
            $db = DB::connection();
            if ($index === 1) {
                $db->beginTransaction();
            }
            try {
                $pool = app(TaskVmSlotPool::class);
                $task = $group === null ? null : Task::topLevel()->findOrFail($group);
                $vm = $ci ? $pool->take($task, $host, true) : $pool->take($task, $host);
                $results[] = ['host' => $vm->host_node_id, 'slot' => $vm->slot_number];
                $db->commit();
            } catch (TaskCapacityException) {
                $db->rollBack();
                $results[] = ['refused' => true];
            }
        }

        return $results;
    } finally {
        foreach ($connections as $name) {
            $db = DB::connection($name);
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            DB::purge($name);
        }
        DB::setDefaultConnection($default);
        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

it('serializes independent SQLite claims', function (string $mode): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id]);
    $hosts = [];
    $nodes = [];
    foreach (['beast', 'shark'] as $index => $name) {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
            'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.'.(7 + $index), 'wireguard_ip' => '10.44.0.'.(7 + $index), 'user' => 'orbit']));
        $nodes[] = $node;
        $hosts[] = new TaskVmHost($node->id, 'orbit-tasks', 'orbittask0', '10.252.'.(7 + $index).'.0/24', 3, 6, '12GiB', '20GiB', ciPlaces: 1);
        TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
            'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => $name.'-base', 'seed' => $name.'-base-seed', 'snapshot' => 'r20261107120000']]]);
        $slots = $mode === 'task' ? ($index === 0 ? [2, 3] : [1, 2, 3]) : [];
        if ($mode !== 'task' && $index === 1) {
            $slots[] = 1;
        }
        foreach ($slots as $slot) {
            TaskVm::query()->create(['host_node_id' => $node->id, 'slot_number' => $slot, 'capacity_pool' => $mode === 'task' ? 'task' : 'ci', 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
                'name' => $name.'-tvm-'.$slot, 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.'.(140 + $index * 5 + $slot)]);
        }
    }
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25', $hosts, '/pi', str_repeat('a', 64), ['gpt-6']));
    $groups = [];
    foreach ([1, 2] as $number) {
        $groups[] = Task::query()->create(['project_id' => $project->id, 'title' => 'Claim '.$number, 'brief' => 'Claim', 'status' => TaskGroupStatus::Todo])->id;
    }
    $claims = $mode === 'refresh' ? [[null, $nodes[0]->id, false], [$groups[1], null, true]]
        : [[$groups[0], null, $mode === 'ci'], [$groups[1], null, $mode === 'ci']];

    $results = interleavedSlotClaims($claims);

    expect($results)->toBe([['host' => $nodes[0]->id, 'slot' => 1], ['refused' => true]]);
})->with(['task', 'ci', 'refresh']);
