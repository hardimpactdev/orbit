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
use Illuminate\Support\Facades\DB;

// No enclosing test transaction: workers must see committed fixtures in a file-backed snapshot.
beforeEach(function (): void {
    $this->artisan('migrate:fresh')->assertSuccessful();
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/** Two independent SQLite writers overlap on the admission write lock, then each runs the real allocator. */
function concurrentSlotClaims(array $claims): array
{
    $file = tempnam(sys_get_temp_dir(), 'orbit-slot-lock-');
    unlink($file);
    DB::statement('VACUUM INTO ?', [$file]);
    $workers = [];
    try {
        foreach ($claims as $index => [$group, $host, $ci]) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork SQLite admission worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $socket = $sockets[1];
                try {
                    DB::purge();
                    config(['database.connections.sqlite.database' => $file]);
                    $db = DB::connection();
                    $db->statement('PRAGMA busy_timeout=0');
                    fwrite($socket, "ready\n");
                    fgets($socket);
                    if ($index === 1) {
                        try {
                            $db->beginTransaction();
                            $db->rollBack();
                            throw new RuntimeException('Expected the other connection to hold the admission lock.');
                        } catch (PDOException $error) {
                            if (! str_contains($error->getMessage(), 'locked')) {
                                throw $error;
                            }
                        }
                        fwrite($socket, "contended\n");
                        $db->statement('PRAGMA busy_timeout=5000');
                    }
                    $db->beginTransaction();
                    if ($index === 0) {
                        fwrite($socket, "locked\n");
                        fgets($socket);
                    }
                    try {
                        $pool = app(TaskVmSlotPool::class);
                        $task = $group === null ? null : Task::topLevel()->findOrFail($group);
                        $vm = $ci ? $pool->take($task, $host, true) : $pool->take($task, $host);
                        $result = ['host' => $vm->host_node_id, 'slot' => $vm->slot_number];
                        $db->commit();
                    } catch (TaskCapacityException) {
                        $db->rollBack();
                        $result = ['refused' => true];
                    }
                    fwrite($socket, json_encode($result)."\n");
                    DB::disconnect();
                    fclose($socket);
                    exit(0);
                } catch (Throwable $error) {
                    fwrite($socket, json_encode(['error' => $error::class.': '.$error->getMessage()])."\n");
                    fclose($socket);
                    exit(1);
                }
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 5);
            $workers[] = [$pid, $sockets[0]];
            // Finish connection/WAL initialization before the other worker opens its connection.
            expect(trim((string) fgets($sockets[0])))->toBe('ready');
        }
        fwrite($workers[0][1], "go\n");
        expect(trim((string) fgets($workers[0][1])))->toBe('locked');
        fwrite($workers[1][1], "go\n");
        expect(trim((string) fgets($workers[1][1])))->toBe('contended');
        fwrite($workers[0][1], "commit\n");
        $results = [];
        foreach ($workers as [$pid, $socket]) {
            $result = json_decode((string) fgets($socket), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($pid, $status);
            if (isset($result['error'])) {
                throw new RuntimeException($result['error']);
            }
            expect(pcntl_wexitstatus($status))->toBe(0);
            $results[] = $result;
        }

        return $results;
    } finally {
        foreach ($workers as [$pid, $socket]) {
            fclose($socket);
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
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
        $hosts[] = new TaskVmHost($node->id, 'orbit-tasks', 'orbittask0', '10.252.'.(7 + $index).'.0/24', 12, 6, '12GiB', '20GiB');
        TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
            'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => $name.'-base', 'seed' => $name.'-base-seed', 'snapshot' => 'r20261107120000']]]);
        $slots = $mode === 'task' && $index === 0 ? [2, 3] : [2, 3, 4];
        if ($mode !== 'task' && $index === 1) {
            $slots[] = 1;
        }
        foreach ($slots as $slot) {
            TaskVm::query()->create(['host_node_id' => $node->id, 'slot_number' => $slot, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
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

    $results = concurrentSlotClaims($claims);

    expect($results)->toBe([['host' => $nodes[0]->id, 'slot' => $mode === 'task' ? 4 : 1], ['refused' => true]]);
})->with(['task', 'ci', 'refresh']);
