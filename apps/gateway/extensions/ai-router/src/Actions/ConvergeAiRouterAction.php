<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostnameRoute;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

/** Uses the normal Gateway scheduler to converge the extension on the activated release. */
final readonly class ConvergeAiRouterAction
{
    public function __construct(
        private ExtensionStore $extensions,
        private AiRouterLifecycleLock $lifecycle,
        private AiRouterState $state,
        private AiRouterPlacement $placement,
        private AiRouterCollectorEnvironment $environment,
        private AiRouterRuntimeLifecycle $runtime,
        private AiRouterPublicationManager $publication,
        private AiRouterRenameCutover $cutover,
        private ProcessSpecification $specifications,
        private ProcessTargetResolver $targets,
        private AiRouterHostnameRoute $routes,
    ) {}

    public function execute(bool $force = false, bool $deferCutover = false): bool
    {
        return $this->lifecycle->run(function () use ($force, $deferCutover): bool {
            if (! $this->state->enabled()) {
                return false;
            }
            $nodeId = $this->state->nodeId();
            $slug = $this->state->cacheConnection();
            if ($nodeId === null || $slug === null) {
                return false;
            }
            $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', AiRouterProcess::NAME)->first();
            $pending = $process !== null && $this->cutover->pending($process);
            if ($deferCutover && $pending) {
                return false;
            }
            if (! $force && ! $pending && ! $this->extensions->enabled('ai-router')) {
                return false;
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
                && $source->source_revision === hash_file('sha256', base_path('extensions/ai-router/resources/collector.py'))) {
                return false;
            }
            $takeover = $this->routes->takeoverCandidate($node);
            if ($pending) {
                $this->cutover->retire($process);
            }
            $this->publication->converge($node, $takeover);
            $this->runtime->converge($node, $environment);
            if ($pending) {
                $this->cutover->complete($process);
            }

            return true;
        });
    }
}
