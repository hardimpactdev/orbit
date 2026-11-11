<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Contracts\Cache\Repository;

/** One native publication per wake, continued by the existing activation-page polls, under the Instance admission lock. */
final readonly class NativeViteCaddyPublication
{
    public function __construct(private StartOperationAction $operations, private Repository $cache) {}

    public function publish(Instance $instance): void
    {
        $id = $this->cache->get($this->key($instance));
        if (! is_int($id)) {
            app(NativeCaddyPublicationInput::class)->forNode($instance->node);
            $operation = $this->operations->execute('route.caddy_converge', 'node_route_projection', $instance->node_id, $instance->node_id,
                ['reason' => 'Vite runtime wake', 'wake_instance_id' => $instance->id, 'wake_vite_port' => $instance->vite_port], 'runtime-activation', null);
            $this->cache->forever($this->key($instance), $operation->id);
        } else {
            $operation = Operation::query()->findOrFail($id);
        }

        if ($operation->status === OperationStatus::Succeeded && ($operation->result['ready'] ?? false) === true
            && ($operation->input['wake_vite_port'] ?? null) === $instance->vite_port) {
            return;
        }
        $deadline = OperationRunBudgets::deadline($operation);
        if ($operation->status->terminal() || ($deadline !== null && ! $deadline->isFuture())) {
            throw new HibernationException('hibernation.publication_failed', "Caddy publication Operation [{$operation->id}] did not complete successfully. Inspect its result before retrying.");
        }

        throw new HibernationException('hibernation.publication_pending', 'Caddy publication is still running.');
    }

    public function retry(Instance $instance): void
    {
        $id = $this->cache->get($this->key($instance));
        $operation = is_int($id) ? Operation::query()->findOrFail($id) : null;
        $deadline = $operation === null ? null : OperationRunBudgets::deadline($operation);
        if ($operation !== null && ! $operation->status->terminal() && ($deadline === null || $deadline->isFuture())) {
            return;
        }
        $this->forget($instance);
    }

    public function forget(Instance $instance): void
    {
        $this->cache->forget($this->key($instance));
    }

    private function key(Instance $instance): string
    {
        return 'hibernation.caddy-publication.'.$instance->id;
    }
}
