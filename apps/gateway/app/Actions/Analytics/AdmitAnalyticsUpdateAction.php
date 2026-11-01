<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class AdmitAnalyticsUpdateAction
{
    public function __construct(
        private StartOperationAction $operations,
        private DockerProcessRenderer $docker,
        private ProcessTargetResolver $targets,
        private AnalyticsRoleSettingsRepository $settings,
    ) {}

    public function execute(string $version): Operation
    {
        $assignment = NodeRole::query()->where('role', RoleName::Analytics)->where('status', LifecycleStatus::Active)->with('node')->first();
        if (! $assignment instanceof NodeRole) {
            throw new ResourceOperationException('analytics.role_missing', 'No Node has an active analytics role.', 409);
        }
        $node = $assignment->node;
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)
            ->where('name', PlausibleProcess::NAME)->where('runtime', ProcessRuntime::Docker)->first();
        if (! $process instanceof Process) {
            throw new ResourceOperationException('analytics.runtime_missing', 'The analytics role has no Plausible Process.', 409);
        }
        if ($process->desired_state !== DesiredProcessState::Running) {
            throw new ResourceOperationException('analytics.runtime_stopped', 'Start the Plausible Process before updating its version.', 409);
        }
        $target = $this->targets->forInstallation($process);
        $environment = ValidatedData::stringMap($process->runtime_config['environment'] ?? []);
        ksort($environment);
        $before = $this->docker->containerInput($process, $target);
        unset($before['environment']);
        $before += ['process_id' => $process->id, 'container' => $this->docker->containerName($process),
            'desired' => 'running', 'spec' => $this->docker->specHash($process, $target)];
        $changed = clone $process;
        $changed->runtime_config = [...$process->runtime_config, 'image' => PlausibleProcess::IMAGE.':v'.$version,
            'command' => PlausibleProcess::Startup,
            'volumes' => array_values(array_filter($before['mounts'],
                static fn (array $volume): bool => $volume['source'] !== '/usr/local/bin/orbit-agent'
                    && $volume['target'] !== '/usr/local/bin/orbit-agent'))];
        $after = $this->docker->containerInput($changed, $target);
        unset($after['environment']);

        return DB::transaction(function () use ($version, $assignment, $node, $process, $before, $after, $changed, $target, $environment): Operation {
            $operation = $this->operations->execute('analytics.update', 'node', $node->id, $node->id,
                ['version' => $version, 'assignment_id' => $assignment->id, 'process_id' => $process->id,
                    'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target->node),
                    'before' => $before, 'after' => [...$after, 'process_id' => $process->id, 'container' => $before['container'], 'desired' => 'running', 'spec' => $this->docker->specHash($changed, $target)],
                    'health_url' => 'http://'.$target->node->wireguard_ip.':'.PlausibleProcess::PORT.'/api/health',
                    'previous_version' => $this->settings->version($node)],
                'operator', request()->attributes->getString('orbit.request_id'));
            if ($operation->wasRecentlyCreated) {
                $reference = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'process-create-operation-'.$operation->id, json_encode($environment, JSON_THROW_ON_ERROR));
                $operation->update(['input' => [...$operation->input, 'execution_secrets' => ['environment' => $reference]]]);
            }

            return $operation;
        });
    }
}
