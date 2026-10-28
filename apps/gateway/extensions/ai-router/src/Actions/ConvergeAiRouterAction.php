<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

/** Uses the normal Gateway scheduler to converge the extension on the activated release. */
final readonly class ConvergeAiRouterAction
{
    public function __construct(
        private ExtensionStore $extensions,
        private AgentDelivery $delivery,
        private AiRouterLifecycleLock $lifecycle,
        private AiRouterState $state,
        private AiRouterPlacement $placement,
        private AiRouterCollectorEnvironment $environment,
        private AdmitAiRouterOperationAction $operations,
        private AiRouterRenameCutover $cutover,
        private ProcessSpecification $specifications,
        private ProcessTargetResolver $targets,
    ) {}

    public function execute(bool $force = false, bool $deferCutover = false): ?Operation
    {
        return $this->lifecycle->run(function () use ($force, $deferCutover): ?Operation {
            if (! $this->state->enabled()) {
                return null;
            }
            $nodeId = $this->state->nodeId();
            $slug = $this->state->cacheConnection();
            if ($nodeId === null || $slug === null) {
                return null;
            }
            $existing = Operation::query()->where('node_id', $nodeId)->whereIn('kind', ['ai_router.setup', 'ai_router.collector_converge'])
                ->whereIn('status', ['queued', 'running', 'waiting'])->first();
            if ($existing !== null) {
                return $existing;
            }
            $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', AiRouterProcess::NAME)->first();
            $pending = $this->cutover->pending($nodeId);
            if ($deferCutover && $pending) {
                return null;
            }
            if (! $force && ! $pending && ! $this->extensions->enabled('ai-router')) {
                return null;
            }
            $node = $this->placement->node($nodeId);
            $connection = $this->placement->connection($slug);
            $environment = $this->environment->build($connection);
            $source = $process === null ? null : AiRouterProcessSource::query()->find($process->id);
            $attributes = $this->specifications->attributes(AiRouterProcess::data($node, $environment), $this->targets->forNodeAdmission($node));
            if (! $force && ! $pending && $process !== null && $this->specifications->matches($process, $attributes)
                && ! $process->systemd_activation_pending
                && $process->status !== LifecycleStatus::Failed
                && $source?->activation_pending === false
                && $source->source_revision === $this->delivery->pin($node)) {
                return null;
            }

            return $this->operations->execute('ai_router.collector_converge', $force ? 'integration' : 'schedule');
        });
    }
}
