<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Instances\Queue\InstanceQueueTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PDO;
use Predis\Client;
use Throwable;

/**
 * Reads an Instance's queue the way Laravel stores it, through the connections registered on the Instance, with a
 * short timeout and read statements only. A job payload is read only to take the job's class and push time.
 */
/**
 * @phpstan-type Job array{id: string, name: string, queue: string, pushed_at: int|float|null, failed_at: ?string, exception: ?string}
 */
final readonly class StoreInstanceQueueReader implements InstanceQueueReader
{
    private const int Timeout = 5;

    private const int ScanRounds = 10;

    private const int MaxQueues = 50;

    /**
     * @param  ?Closure(DatabaseConnection): ConnectionInterface  $databases
     * @param  ?Closure(DatabaseConnection): Client  $redis
     */
    public function __construct(private ?Closure $databases = null, private ?Closure $redis = null) {}

    public function read(InstanceQueueTarget $target, string $state, int $limit): array
    {
        try {
            $report = $target->driver === 'redis' ? $this->redisQueue($target, $state, $limit) : $this->databaseQueue($target, $state, $limit);
            $failed = $target->failed === null ? null : $this->failed($target->failed, $state, $limit);
        } catch (Throwable) {
            throw new ResourceOperationException('instance.queue_failed', 'The queue of the Instance could not be read.', 502);
        }

        return [
            'pending' => $report['pending'],
            'failed' => $failed['count'] ?? null,
            'queues' => $report['queues'],
            'jobs' => $state === 'failed' ? ($failed['jobs'] ?? []) : $report['jobs'],
        ];
    }

    /** @return array{pending: int, queues: list<array{name: string, length: int}>, jobs: list<Job>} */
    private function redisQueue(InstanceQueueTarget $target, string $state, int $limit): array
    {
        $client = $this->client($target->store);
        $names = $this->queueNames($client, $target);
        $replies = $names === [] ? [] : $client->pipeline(static function ($pipe) use ($names, $target): void {
            foreach ($names as $name) {
                $pipe->llen($target->prefix.'queues:'.$name);
                $pipe->zcard($target->prefix.'queues:'.$name.':delayed');
            }
        });
        $counts = is_array($replies) ? array_values($replies) : [];
        $queues = [];
        foreach ($names as $index => $name) {
            $queues[] = ['name' => $name, 'length' => (int) ($counts[2 * $index] ?? 0) + (int) ($counts[2 * $index + 1] ?? 0)];
        }
        usort($queues, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        $jobs = [];
        if ($state === 'pending') {
            // Laravel pushes to the tail of a queue list, so the newest jobs are the last ones.
            foreach (array_slice(array_filter($queues, static fn (array $queue): bool => $queue['length'] > 0), 0, 5) as $queue) {
                foreach (array_reverse($client->lrange($target->prefix.'queues:'.$queue['name'], -$limit, -1)) as $payload) {
                    $jobs[] = $this->job($payload, $queue['name']);
                }
            }
            $jobs = array_slice($jobs, 0, $limit);
        }

        return ['pending' => array_sum(array_column($queues, 'length')), 'queues' => $queues, 'jobs' => $jobs];
    }

    /** @return list<string> */
    private function queueNames(Client $client, InstanceQueueTarget $target): array
    {
        $names = $target->queues;
        $cursor = '0';
        for ($round = 0; $round < self::ScanRounds && count($names) < self::MaxQueues; $round++) {
            [$cursor, $keys] = $client->scan($cursor, ['MATCH' => addcslashes($target->prefix, '*?[]\\').'queues:*', 'COUNT' => 200]);
            foreach ($keys as $key) {
                $name = substr((string) $key, strlen($target->prefix.'queues:'));
                // The delayed and reserved sets and the notify list live beside a queue list.
                if ($name !== '' && preg_match('/:(delayed|reserved|notify)\z/', $name) !== 1) {
                    $names[] = $name;
                }
            }
            if ((string) $cursor === '0') {
                break;
            }
        }

        return array_slice(array_values(array_unique($names)), 0, self::MaxQueues);
    }

    /** @return array{pending: int, queues: list<array{name: string, length: int}>, jobs: list<Job>} */
    private function databaseQueue(InstanceQueueTarget $target, string $state, int $limit): array
    {
        $database = $this->database($target->store);
        $queues = [];
        foreach ($database->table('jobs')->whereNull('reserved_at')->selectRaw('queue, count(*) as total')->groupBy('queue')->get() as $row) {
            $queues[] = ['name' => (string) $row->queue, 'length' => (int) $row->total];
        }
        usort($queues, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        $jobs = [];
        if ($state === 'pending') {
            foreach ($database->table('jobs')->whereNull('reserved_at')->orderByDesc('id')->limit($limit)->get(['id', 'queue', 'payload', 'created_at']) as $row) {
                $job = $this->job((string) $row->payload, (string) $row->queue, (string) $row->id);
                $job['pushed_at'] ??= is_numeric($row->created_at) ? (int) $row->created_at : null;
                $jobs[] = $job;
            }
        }

        return ['pending' => array_sum(array_column($queues, 'length')), 'queues' => array_slice($queues, 0, self::MaxQueues), 'jobs' => $jobs];
    }

    /** @return array{count: int, jobs: list<Job>}|null Null when a pending read finds no readable `failed_jobs` table. */
    private function failed(DatabaseConnection $connection, string $state, int $limit): ?array
    {
        try {
            $database = $this->database($connection);
            $count = (int) $database->table('failed_jobs')->count();
        } catch (Throwable $error) {
            return $state === 'failed' ? throw $error : null;
        }

        $jobs = [];
        if ($state === 'failed') {
            foreach ($database->table('failed_jobs')->orderByDesc('id')->limit($limit)->get(['uuid', 'id', 'queue', 'payload', 'exception', 'failed_at']) as $row) {
                $jobs[] = $this->job(
                    (string) $row->payload,
                    (string) $row->queue,
                    (string) ($row->uuid ?? $row->id),
                    is_string($row->failed_at) ? $row->failed_at : null,
                    strtok((string) $row->exception, "\n") ?: null,
                );
            }
        }

        return ['count' => $count, 'jobs' => $jobs];
    }

    /** @return Job */
    private function job(string $payload, string $queue, ?string $id = null, ?string $failedAt = null, ?string $exception = null): array
    {
        $decoded = json_decode($payload, true);
        $decoded = is_array($decoded) ? $decoded : [];

        return [
            'id' => $id ?? (is_string($decoded['uuid'] ?? null) ? $decoded['uuid'] : (is_scalar($decoded['id'] ?? null) ? (string) $decoded['id'] : '')),
            'name' => is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : '',
            'queue' => $queue,
            'pushed_at' => is_numeric($decoded['pushedAt'] ?? null) ? $decoded['pushedAt'] + 0 : null,
            'failed_at' => $failedAt,
            'exception' => $exception,
        ];
    }

    private function database(DatabaseConnection $connection): ConnectionInterface
    {
        if ($this->databases instanceof Closure) {
            return ($this->databases)($connection);
        }

        $pgsql = $connection->driver === DatabaseDriver::Pgsql;
        $database = DB::build([
            'driver' => $pgsql ? 'pgsql' : 'mysql',
            'host' => $connection->host,
            'port' => $connection->port ?? ($connection->driver === DatabaseDriver::Pgsql ? 5432 : 3306),
            'database' => $connection->database,
            'username' => $connection->username,
            'password' => $connection->password,
            'options' => [PDO::ATTR_TIMEOUT => self::Timeout],
        ]);
        // The connect timeout does not bound a statement.
        $database->statement($pgsql ? 'set statement_timeout = 5000' : 'set session max_execution_time = 5000');

        return $database;
    }

    private function client(DatabaseConnection $connection): Client
    {
        if ($this->redis instanceof Closure) {
            return ($this->redis)($connection);
        }

        return new Client([
            'scheme' => 'tcp',
            'host' => $connection->host,
            'port' => $connection->port ?? 6379,
            'username' => $connection->username,
            'password' => $connection->password,
            'database' => is_numeric($connection->database) ? (int) $connection->database : 0,
            'timeout' => (float) self::Timeout,
            'read_write_timeout' => (float) self::Timeout,
        ]);
    }
}
