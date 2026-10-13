<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Actions\Processes\CreateProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Artisan;

/**
 * Laravel Horizon runs as a Process of the Gateway Node (docs/reference/gateway-queue.md#horizon). A release creates it
 * once and afterwards runs `horizon:terminate`: the master waits for its running jobs, exits, and the Process's restart
 * policy starts it again on the new release.
 */
final readonly class GatewayHorizon
{
    public const string Name = 'horizon';

    public function __construct(private CreateProcessAction $processes) {}

    /** A Process that runs `<php> artisan horizon`. */
    public static function runs(Process $process): bool
    {
        $command = $process->runtime_config['command'] ?? null;

        return is_array($command) && array_slice($command, 1, 2) === ['artisan', 'horizon'];
    }

    /**
     * Terminates the running master, so its Process starts it again on the current release once its running jobs end.
     * A Gateway without a Process that runs Horizon gets one beside the scheduler, with its PHP and directory, and it
     * starts at once. An existing Process is never converged again: that would restart its unit and kill running jobs.
     *
     * @return 'created'|'terminated'|'none'
     */
    public function converge(Node $gateway, Process $scheduler): string
    {
        if (! GatewayQueueRedis::configured()) {
            return 'none';
        }

        $exists = Process::query()->whereMorphedTo('owner', $gateway)
            ->where('runtime_config->command[1]', 'artisan')
            ->where('runtime_config->command[2]', 'horizon')
            ->exists();

        if ($exists) {
            Artisan::call('horizon:terminate');

            return 'terminated';
        }

        $command = $scheduler->runtime_config['command'] ?? null;
        $this->processes->execute(new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $gateway->id,
            name: self::Name,
            runtime: ProcessRuntime::Systemd,
            command: [is_array($command) && is_string($command[0] ?? null) ? $command[0] : 'php', 'artisan', 'horizon'],
            image: null,
            workingDirectory: $scheduler->working_directory,
            environment: [],
            ports: [],
            volumes: [],
            restartPolicy: 'always',
            start: true,
        ));

        return 'created';
    }
}
