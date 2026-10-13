<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/** Reads the Gateway host's processes from `/proc` and Horizon's state from Redis. */
final readonly class NativeGatewayHorizonRuntime implements GatewayHorizonRuntime
{
    public function __construct(private string $proc = '/proc') {}

    public function taskVmWorkers(): array
    {
        $pids = [];

        foreach (glob($this->proc.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $directory) {
            $arguments = explode("\0", (string) @file_get_contents($directory.'/cmdline'));

            if (in_array('horizon:work', $arguments, true) && in_array('--queue='.GatewayQueue::TaskVms->value, $arguments, true)) {
                $pids[] = (int) basename($directory);
            }
        }

        return $pids;
    }

    public function running(int $pid): bool
    {
        return is_dir($this->proc.'/'.$pid);
    }

    public function masters(): array
    {
        return array_values(array_map(
            static fn (object $master): string => (string) ($master->name ?? ''),
            app(MasterSupervisorRepository::class)->all(),
        ));
    }

    public function pauseTaskVms(int $seconds): void
    {
        app(QueueManager::class)->pauseFor(GatewayQueue::Connection, GatewayQueue::TaskVms->value, $seconds);
    }

    public function resumeTaskVms(): void
    {
        app(QueueManager::class)->resume(GatewayQueue::Connection, GatewayQueue::TaskVms->value);
    }

    public function terminate(): void
    {
        Artisan::call('horizon:terminate');
    }

    public function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
