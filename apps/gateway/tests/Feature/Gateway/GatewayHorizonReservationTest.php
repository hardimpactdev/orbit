<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Queue\LuaScripts;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisQueue;

final class GatewayHorizonReservationProbe
{
    public static int $handled = 0;

    public function fire(Job $job): void
    {
        self::$handled++;
        $job->delete();
    }
}

describe('Horizon reservation metadata', function (): void {
    it('runs and acknowledges the reserved job once even when its metadata write fails', function (bool $metadataFails): void {
        $this->freezeTime();
        Log::spy();
        GatewayHorizonReservationProbe::$handled = 0;
        config(['queue.connections.gateway' => [
            'driver' => 'redis', 'connection' => 'gateway', 'queue' => 'operations', 'retry_after' => 1800, 'block_for' => 5,
        ]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('isCluster')->andReturnFalse();
        $redis = Mockery::mock(Factory::class);
        $redis->shouldReceive('connection')->with('gateway')->andReturn($connection);
        app()->instance('redis', $redis);
        $queue = Queue::connection(GatewayQueue::Connection);
        expect($queue)->toBeInstanceOf(RedisQueue::class);
        $payload = json_encode([
            'uuid' => '479aa82b-8410-4c78-ac77-03f5d64023e7',
            'id' => '479aa82b-8410-4c78-ac77-03f5d64023e7',
            'job' => GatewayHorizonReservationProbe::class.'@fire',
            'displayName' => GatewayHorizonReservationProbe::class,
            'data' => [],
            'attempts' => 0,
            'maxTries' => 1,
        ], JSON_THROW_ON_ERROR);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $decoded['attempts'] = 1;
        $reserved = json_encode($decoded, JSON_THROW_ON_ERROR);
        $uuid = $decoded['uuid'];
        foreach (['delayed', 'reserved'] as $source) {
            $connection->shouldReceive('eval')->once()->with(
                LuaScripts::migrateExpiredJobs(), 3, 'queues:operations:'.$source, 'queues:operations', 'queues:operations:notify', now()->timestamp, -1,
            )->andReturn([]);
        }
        $connection->shouldReceive('eval')->once()->with(
            LuaScripts::pop(), 3, 'queues:operations', 'queues:operations:reserved', 'queues:operations:notify', now()->timestamp + 1800,
        )->andReturn([$payload, $reserved]);
        $connection->shouldReceive('zrem')->once()->with('queues:operations:reserved', $reserved)->andReturn(1);
        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('migrated')->twice()->with('gateway', 'operations', Mockery::on(
            static fn (Collection $payloads): bool => $payloads->isEmpty(),
        ));
        $reservation = $jobs->shouldReceive('reserved')->once()->with('gateway', 'operations', Mockery::on(
            static fn (JobPayload $payload): bool => $payload->id() === $uuid && $payload['attempts'] === 1,
        ));
        if ($metadataFails) {
            $reservation->andThrow(new RuntimeException('Stream is already at the end'));
        }
        $jobs->shouldReceive('completed')->once()->with(Mockery::type(JobPayload::class), false, false);
        app()->instance(JobRepository::class, $jobs);
        $tags = Mockery::mock(TagRepository::class);
        $tags->shouldReceive('monitored')->once()->andReturn([]);
        app()->instance(TagRepository::class, $tags);
        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('incrementQueue')->once()->with('operations', Mockery::type('float'));
        $metrics->shouldReceive('incrementJob')->once()->with(GatewayHorizonReservationProbe::class, Mockery::type('float'));
        app()->instance(MetricsRepository::class, $metrics);

        $worker = app('queue.worker');
        assert($worker instanceof Worker);
        $worker->runNextJob('gateway', 'operations', new WorkerOptions(sleep: 0, maxTries: 1));

        expect(GatewayHorizonReservationProbe::$handled)->toBe(1);
        if ($metadataFails) {
            Log::shouldHaveReceived('error')->once()->with('Horizon could not record a job reservation.', [
                'job_uuid' => $uuid,
                'connection' => 'gateway',
                'queue' => 'operations',
                'host' => gethostname(),
                'pid' => getmypid(),
                'attempts' => 1,
                'exception_class' => RuntimeException::class,
            ]);
        } else {
            Log::shouldNotHaveReceived('error');
        }
    })->with(['healthy metadata' => false, 'failed metadata write' => true]);
});
