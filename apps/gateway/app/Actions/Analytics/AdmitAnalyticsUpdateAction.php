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
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

final readonly class AdmitAnalyticsUpdateAction
{
    public function __construct(
        private StartOperationAction $operations,
        private AgentDelivery $agents,
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
        $changed->runtime_config = [...$process->runtime_config, 'image' => PlausibleProcess::IMAGE.':v'.$version];

        return $this->operations->execute('analytics.update', 'node', $node->id, $node->id,
            ['version' => $version, 'assignment_id' => $assignment->id, 'process_id' => $process->id, 'agent_sha256' => $this->agents->pin($node),
                'runtime_environment' => ['$secret' => 'analytics.runtime_environment', 'id' => $node->id],
                'before' => $before, 'after' => [...$before, 'image' => $changed->runtime_config['image'], 'spec' => $this->docker->specHash($changed, $target)],
                'environment_sha256' => hash('sha256', json_encode($environment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'health_url' => 'http://'.$target->node->wireguard_ip.':'.PlausibleProcess::PORT.'/api/health',
                'previous_version' => $this->settings->version($node)],
            'operator', request()->attributes->getString('orbit.request_id'));
    }
}
