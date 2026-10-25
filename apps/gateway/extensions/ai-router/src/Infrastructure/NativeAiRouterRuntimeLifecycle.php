<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Actions\Processes\CreateProcessAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\UpdateProcessAction;
use App\Data\Processes\UpdateProcessData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;
use SensitiveParameter;

final readonly class NativeAiRouterRuntimeLifecycle implements AiRouterRuntimeLifecycle
{
    public function __construct(
        private CreateProcessAction $add,
        private DestroyProcessAction $remove,
        private AiRouterSourceRemoval $source,
        private AgentDelivery $delivery,
        private UpdateProcessAction $update,
    ) {}

    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
    ): void {
        $revision = $this->delivery->pin($node);
        $existing = Process::query()->where('owner_type', Node::class)
            ->where('owner_id', $node->id)->where('name', AiRouterProcess::NAME)->first();
        $source = $existing === null ? null : AiRouterProcessSource::query()->firstOrNew(['process_id' => $existing->id]);
        if ($source !== null && $source->source_revision !== $revision) {
            $source->activation_pending = true;
            $source->source_revision = null;
            $source->save();
        }
        $this->source->remove($node);
        if ($existing !== null && $source?->activation_pending) {
            // Unit convergence consumes source activation intent, even when the unit bytes already match.
            $existing->systemd_activation_pending = true;
            $existing->save();
        }
        $data = AiRouterProcess::data($node, $environment);

        try {
            $result = $this->add->execute($data);
            $process = $result['process'];
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'process.name_taken') {
                throw $exception;
            }

            $process = Process::query()->where('owner_type', Node::class)
                ->where('owner_id', $node->id)->where('name', AiRouterProcess::NAME)->sole();
            $process = $this->update->execute($process, new UpdateProcessData($data, [
                'runtime', 'command', 'image', 'working_directory', 'environment', 'ports', 'volumes',
                'restart_policy', 'keep_alive', 'restart_on_deploy', 'user',
            ]));
        }

        $source ??= AiRouterProcessSource::query()->firstOrNew(['process_id' => $process->id]);

        $source->source_revision = $revision;
        $source->activation_pending = false;
        $source->save();
    }

    public function remove(Node $node): void
    {
        $processes = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', AiRouterProcess::NAME)
            ->get();

        foreach ($processes as $process) {
            $this->remove->execute($process, removedByOwningRole: true);
        }

        $this->source->remove($node);
    }
}
