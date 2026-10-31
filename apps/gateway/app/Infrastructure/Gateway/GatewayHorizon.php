<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Processes\RecordProcessIntentAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Support\Facades\DB;

/** Records the fixed Horizon intent. The placed native run owns installation and handoff. */
final readonly class GatewayHorizon
{
    public const string Name = 'horizon';

    public function __construct(private RecordProcessIntentAction $records, private ProcessTargetResolver $targets,
        private SystemdProcessRenderer $renderer, private StartOperationAction $operations) {}

    public static function longestJobSeconds(): int
    {
        $timeouts = array_map(static fn (mixed $supervisor): int => is_array($supervisor) && is_int($supervisor['timeout'] ?? null) ? $supervisor['timeout'] : 0,
            (array) config('horizon.defaults', []));

        return max([0, ...$timeouts]);
    }

    public static function runs(Process $process): bool
    {
        $command = $process->runtime_config['command'] ?? null;

        return is_array($command) && array_slice($command, 1, 2) === ['artisan', 'horizon'];
    }

    /** @param Closure(): Process $scheduler
     * @return array{outcome:string,operation_id?:int}
     */
    public function converge(Node $gateway, Closure $scheduler): array
    {
        if (! GatewayQueueRedis::configured()) {
            return ['outcome' => 'none'];
        }

        $operation = null;
        try {
            return DB::transaction(function () use ($gateway, $scheduler, &$operation): array {
                $target = $this->targets->forNodeAdmission($gateway);
                $process = self::find($gateway);
                $created = $process === null;
                if ($process === null) {
                    $source = $scheduler();
                    $command = $source->runtime_config['command'] ?? null;
                    $process = $this->records->execute(new AddProcessData(
                        targetType: ProcessTargetType::Node, targetId: $gateway->id, name: self::Name,
                        runtime: ProcessRuntime::Systemd,
                        command: [is_array($command) && is_string($command[0] ?? null) ? $command[0] : PHP_BINARY, 'artisan', 'horizon'],
                        image: null, workingDirectory: $source->working_directory, environment: [], ports: [], volumes: [], restartPolicy: 'always', start: true,
                    ), $target)['process'];
                }
                // Horizon reads cached application configuration; its unit has no execution secret fields.
                if ($process->runtime !== ProcessRuntime::Systemd || ($process->runtime_config['environment'] ?? []) !== []
                    || ! in_array($process->runtime_config['environment_file'] ?? null, [null, ''], true)) {
                    throw new ResourceOperationException('process.horizon_invalid', 'Horizon requires its fixed systemd Process without managed environment overrides.', 409);
                }
                $content = $this->renderer->render($process, $target);
                $operation = $this->operations->execute('process.horizon_handoff', 'process', $process->id, $gateway->id,
                    ['process_id' => $process->id, 'unit' => $this->renderer->unitName($process), 'content' => $content,
                        'content_hash' => hash('sha256', $content), 'command' => $process->runtime_config['command'],
                        'directory' => $process->working_directory, 'user' => $process->runtime_config['user'] ?? $target->user, 'created' => $created], 'gateway-release', null);
                $process->update(['desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);

                return ['outcome' => 'submitted', 'operation_id' => $operation->id];
            });
        } catch (ResourceOperationException $exception) {
            if ($operation?->refresh()->status === OperationStatus::Failed) {
                Process::query()->whereKey($operation->target_id)->update([
                    'status' => LifecycleStatus::Failed, 'failed_step' => null, 'error_code' => $operation->error_code,
                ]);
            }
            throw $exception;
        }
    }

    public static function find(Node $gateway): ?Process
    {
        return Process::query()->whereMorphedTo('owner', $gateway)
            ->where('runtime_config->command[1]', 'artisan')
            ->where('runtime_config->command[2]', 'horizon')
            ->orderBy('id')
            ->first();
    }
}
