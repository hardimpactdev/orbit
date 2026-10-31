<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationSecretValue;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

/** Read-only capture. Missing Process intent must be prepared by its owning admission. */
final readonly class AiRouterCollectorSnapshot
{
    public const string Source = 'ai_router.collector_installation';

    public const string Parameter = 'collector_installation';

    public function __construct(
        private AiRouterState $state,
        private AiRouterPlacement $placement,
        private AiRouterCollectorEnvironment $environment,
        private AiRouterRenameCutover $cutover,
        private AgentDelivery $delivery,
        private ProcessSpecification $specifications,
        private ProcessTargetResolver $targets,
        private SystemdProcessRenderer $renderer,
    ) {}

    /** @return array<string,mixed> */
    public function capture(Node $node): array
    {
        if (! $this->applicable($node)) {
            return ['schema_version' => 1, 'node_id' => $node->id, 'applicable' => false,
                'installation' => ['$secret' => self::Source, 'id' => $node->id]];
        }

        return $this->describe($node)['public'];
    }

    public function applicable(Node $node): bool
    {
        return $this->state->enabled() && $this->state->nodeId() === $node->id;
    }

    public function pin(Node $node): string
    {
        return $this->delivery->pin($node);
    }

    /** @param array<string,mixed> $admitted */
    public function privateMaterial(Node $node, array $admitted): OperationSecretValue
    {
        $current = $this->describe($node);
        if ($current['public'] !== $admitted) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector intent changed after admission.', 422);
        }

        return new OperationSecretValue(['runtime' => 'systemd', 'content' => $current['content']], $current['redactions']);
    }

    /** @return array{public:array<string,mixed>,content:string,redactions:list<string>} */
    private function describe(Node $node): array
    {
        $this->state->assertEnabled();
        if ($this->state->nodeId() !== $node->id) {
            throw new ResourceOperationException('ai_router.collector_owner_changed', 'The Node does not own the collector.', 409);
        }
        $this->placement->node($node->id);
        if ($this->cutover->pending($node->id)) {
            throw new ResourceOperationException('ai_router.cutover_pending', 'The collector rename must finish before this footprint.', 409);
        }
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', AiRouterProcess::NAME)->first();
        $source = $process === null ? null : AiRouterProcessSource::query()->find($process->id);
        if ($process === null || $source === null || $process->runtime !== ProcessRuntime::Systemd
            || $process->source_definition_id !== null
            || ($process->runtime_config['command'] ?? null) !== [AiRouterProcess::EXECUTABLE, 'service', AiRouterProcess::NAME]) {
            throw new ResourceOperationException('ai_router.collector_intent_required', 'The collector needs an owned Process intent before capture.', 409);
        }
        $slug = $this->state->cacheConnection();
        if ($slug === null) {
            throw new ResourceOperationException('ai_router.cache_missing', 'The collector cache connection is missing.', 422);
        }
        $connection = $this->placement->connection($slug);
        $environment = $this->environment->build($connection);
        $target = $this->targets->forNodeAdmission($node);
        $desired = clone $process;
        $desired->forceFill($this->specifications->attributes(AiRouterProcess::data($node, $environment), $target));
        $content = $this->renderer->render($desired, $target);
        $pin = $this->delivery->pin($node);

        return ['public' => [
            'schema_version' => 1,
            'node_id' => $node->id,
            'applicable' => true,
            'process' => ['process_id' => $process->id, 'agent_sha256' => $pin, 'runtime' => 'systemd',
                'name' => $this->renderer->unitName($desired), 'desired' => 'running', 'on_demand' => false,
                'retired' => null, 'content_hash' => hash('sha256', $content),
                'activation_pending' => $process->systemd_activation_pending || $source->activation_pending || $source->source_revision !== $pin],
            'installation' => ['$secret' => self::Source, 'id' => $node->id],
        ], 'content' => $content, 'redactions' => array_values($environment)];
    }
}
