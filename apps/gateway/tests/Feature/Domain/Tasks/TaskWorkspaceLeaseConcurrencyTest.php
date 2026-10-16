<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Models\Task;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('serializes independent groups and preserves FIFO ownership across a process restart', function (): void {
    tick_bind_defaults();
    $first = tick_group();
    $second = $first->replicate();
    $second->title = 'Another group sharing the Instance';
    $second->save();
    $second->setRelation('taskable', $first->taskable);
    $original = config('database.default');
    $database = tempnam(sys_get_temp_dir(), 'orbit-lease-concurrency-');
    $directory = $database.'-locks';
    mkdir($directory, 0o700);
    $children = [];
    config(['database.connections.lease_concurrency' => [
        ...config('database.connections.sqlite'), 'database' => $database,
    ], 'database.default' => 'lease_concurrency']);

    try {
        Schema::create('tasks', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('status');
        });
        DB::table('tasks')->insert([
            ['id' => $first->id, 'parent_id' => null, 'status' => 'running'],
            ['id' => $second->id, 'parent_id' => null, 'status' => 'running'],
        ]);
        (require database_path('migrations/2026_10_16_074802_create_task_workspace_leases_table.php'))->up();
        (require database_path('migrations/2026_10_16_111138_create_task_workspace_operations_table.php'))->up();
        $first->setConnection('lease_concurrency');
        $second->setConnection('lease_concurrency');

        $spawn = function (Task $group, string $owner) use (&$children, $directory): int {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_timeout($parent, 10);
            stream_set_timeout($child, 10);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork a lease contender.');
            }
            if ($pid === 0) {
                fclose($parent);
                foreach ($children as $other) {
                    fclose($other['socket']);
                }
                DB::purge('lease_concurrency');
                app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
                $leases = new TaskWorkspaceLease;
                $lease = null;
                try {
                    lease_concurrency_write($child, ['ready' => true]);
                    while (($command = fgets($child)) !== false) {
                        if (trim($command) === 'exit') {
                            exit(0);
                        }
                        if (trim($command) === 'release') {
                            if (! $lease instanceof TaskWorkspaceLeaseRecord) {
                                throw new RuntimeException('The contender has no lease to release.');
                            }
                            $leases->release($lease);
                            lease_concurrency_write($child, ['status' => 'released']);

                            continue;
                        }
                        try {
                            $lease = $leases->acquire($group, 'publication', $owner);
                            lease_concurrency_write($child, ['status' => 'held', 'id' => $lease->id]);
                        } catch (TaskWorkspaceWaiting) {
                            lease_concurrency_write($child, ['status' => 'queued']);
                        }
                    }
                    exit(0);
                } catch (Throwable $exception) {
                    lease_concurrency_write($child, ['error' => $exception->getMessage()]);
                    exit(1);
                }
            }
            fclose($child);
            $children[$pid] = ['socket' => $parent, 'reaped' => false];
            expect(lease_concurrency_read($parent))->toBe(['ready' => true]);

            return $pid;
        };

        $one = $spawn($first, 'first-process');
        $two = $spawn($second, 'second-process');
        // Both processes are ready before either receives admission permission.
        fwrite($children[$one]['socket'], "acquire\n");
        fwrite($children[$two]['socket'], "acquire\n");
        $results = [
            $one => lease_concurrency_read($children[$one]['socket']),
            $two => lease_concurrency_read($children[$two]['socket']),
        ];
        expect(array_column($results, 'status'))->toContain('held', 'queued');
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(1);
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(1);
        $winner = $results[$one]['status'] === 'held' ? $one : $two;
        $waiter = $winner === $one ? $two : $one;
        $heldId = $results[$winner]['id'];

        fwrite($children[$winner]['socket'], "exit\n");
        pcntl_waitpid($winner, $exitStatus);
        $children[$winner]['reaped'] = true;
        expect(pcntl_wexitstatus($exitStatus))->toBe(0);

        // A fresh OS process and SQLite connection cannot take a dead process's durable lease.
        $restart = $spawn($first, 'restarted-process');
        fwrite($children[$restart]['socket'], "acquire\n");
        expect(lease_concurrency_read($children[$restart]['socket']))->toBe(['status' => 'queued']);
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->id)->toBe($heldId);
        fwrite($children[$restart]['socket'], "exit\n");
        pcntl_waitpid($restart, $exitStatus);
        $children[$restart]['reaped'] = true;
        expect(pcntl_wexitstatus($exitStatus))->toBe(0);

        // Explicit release is required, and the earlier waiter keeps precedence after release.
        DB::purge('lease_concurrency');
        app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
        $fresh = new TaskWorkspaceLease;
        $fresh->release(TaskWorkspaceLeaseRecord::query()->findOrFail($heldId));
        expect(fn () => $fresh->acquire($first, 'publication', 'restarted-process'))->toThrow(TaskWorkspaceWaiting::class);
        fwrite($children[$waiter]['socket'], "acquire\n");
        $admitted = lease_concurrency_read($children[$waiter]['socket']);
        expect($admitted['status'])->toBe('held');
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->id)->toBe($admitted['id']);
        fwrite($children[$waiter]['socket'], "release\n");
        expect(lease_concurrency_read($children[$waiter]['socket']))->toBe(['status' => 'released']);

        $last = $fresh->acquire($first, 'publication', 'restarted-process');
        expect($last->status)->toBe('held');
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
        $fresh->release($last);
    } finally {
        foreach ($children as $pid => $child) {
            fclose($child['socket']);
            if (! $child['reaped']) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
        }
        DB::purge('lease_concurrency');
        config(['database.default' => $original]);
        foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});

/** @param resource $socket @param array<string, mixed> $message */
function lease_concurrency_write($socket, array $message): void
{
    fwrite($socket, json_encode($message, JSON_THROW_ON_ERROR)."\n");
}

/** @param resource $socket @return array<string, mixed> */
function lease_concurrency_read($socket): array
{
    $line = fgets($socket);
    if ($line === false) {
        throw new RuntimeException('The lease contender closed or timed out before its reply.');
    }
    $reply = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if (isset($reply['error'])) {
        throw new RuntimeException($reply['error']);
    }

    return $reply;
}

it('refuses a real cancellation request while another process records group completion', function (): void {
    tick_bind_defaults();
    $group = tick_group();
    $directory = sys_get_temp_dir().'/orbit-action-contention-'.bin2hex(random_bytes(8));
    app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
    [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_timeout($parent, 5);
    $pid = null;
    try {
        app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $directory, $parent, $child, &$pid): void {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork a cancellation request.');
            }
            if ($pid === 0) {
                fclose($parent);
                app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
                app()->forgetInstance(CancelTaskGroupAction::class);
                try {
                    app(CancelTaskGroupAction::class)->execute($group);
                    lease_concurrency_write($child, ['status' => 'unexpected-cancel']);
                } catch (TaskWorkspaceWaiting) {
                    lease_concurrency_write($child, ['status' => 'busy']);
                } catch (Throwable $exception) {
                    lease_concurrency_write($child, ['error' => $exception->getMessage()]);
                }
                exit(0);
            }
            fclose($child);
            // The holder remains locked until the complete public request has answered.
            expect(lease_concurrency_read($parent))->toBe(['status' => 'busy']);
            expect($group->fresh()->status->value)->toBe('running');
            expect(TaskWorkspaceLeaseRecord::query()->count())->toBe(0);
        });
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
        $pid = null;
    } finally {
        fclose($parent);
        if (is_resource($child)) {
            fclose($child);
        }
        if (is_int($pid) && $pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});
