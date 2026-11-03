<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\NodeFact;
use App\Support\ValidatedData;

/**
 * env:sync's check of the `.env` on the Node, from the key names the Node agent reports (Nick 2026-11-03): no run reads
 * the Node. Orbit writes a managed `.env` as 0640 with group `orbit-env`, so the agent can read its key names. Only a
 * regular file whose names the agent read counts: a hand-made 0600 file, a link, a silent agent, or a report of no
 * file leave them not observed, because an agent that can't reach the directory also sees no file.
 */
final readonly class ObservedInstanceEnvironment
{
    public function __construct(private NodeAgentLiveness $liveness, private InstanceEnvironmentStore $store) {}

    /**
     * Refuses a sync that would drop keys only the file holds, or that can't see the file's keys, unless `$force`.
     * Returns the status of a `.env.testing` the run must not write, or null: it may write one only when the agent
     * read the `.env` and reports that Git does not track `.env.testing`.
     */
    public function admit(Instance $instance, InstanceEnvironmentContext $context, bool $force): ?string
    {
        $file = $context->path.'/.env';
        $fact = NodeFact::query()->where('node_id', $context->nodeId)->where('kind', NodeFactKind::EnvKeys->value)
            ->where('subject', $file)->first();
        $data = $fact instanceof NodeFact ? $fact->data : null;
        $keys = $data === null || ($data['kind'] ?? null) !== 'regular' ? null : $data['keys'] ?? null;
        $observed = is_array($keys) && $this->liveness->for($instance->node)?->state === NodeAgentState::Online;
        if (! $observed) {
            if (! $force) {
                throw new ResourceOperationException('env.keys_not_observed',
                    "The Node agent has not reported the key names of {$file}. Run env:import, then env:sync --force; --force alone replaces the file.", 409);
            }

            return InstanceTestEnvironmentOutcome::SkippedUnobserved;
        }
        if (! $force) {
            $unowned = $this->store->synchronizationSnapshot($context)->unownedKeysIn(ValidatedData::stringList($keys));
            if ($unowned !== []) {
                throw new ResourceOperationException('env.sync_would_drop_keys',
                    'The .env holds keys that stored configuration lacks. Run env:import first, or env:sync --force to drop them.', 409,
                    details: ['keys' => implode(',', $unowned)]);
            }
        }

        return ($data['testing_tracked'] ?? true) === false ? null : InstanceTestEnvironmentOutcome::SkippedTracked;
    }
}
