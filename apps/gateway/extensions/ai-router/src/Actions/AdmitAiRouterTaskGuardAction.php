<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * Records the intent of the task VM guard on the router's Node (docs/cli/ai-router/task-guard.mmd): its Caddyfile, its UFW rules and
 * vault copies of the task key and the anchor key. Swamp writes them on the Node and proves the guard refuses what it must. With
 * `$remove`, the run takes the guard, its keys and its rules off the Node instead.
 *
 * The guard renders from the last succeeded guard and forwarder runs, so it renders in the same SQLite writer transaction that takes the
 * guard's lock: a run that ends before it is in the render, and a run that still holds the lock refuses with `operation.target_busy`.
 */
final readonly class AdmitAiRouterTaskGuardAction
{
    public function __construct(private AiRouterState $state, private AiRouterTaskGuard $guard, private StartOperationAction $start) {}

    public function execute(bool $remove = false, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        if (! $remove) {
            $this->state->assertEnabled();
        }
        $node = $this->guard->node() ?? throw new ResourceOperationException('ai_router.disabled', 'The ai-router has no Node.', 409);
        $secrets = $this->guard->executionSecrets($node);

        return DB::transaction(fn (): Operation => $this->admit($node, $remove, $secrets, $triggeredBy, $requestId));
    }

    /**
     * For the release's guard phase ([The guard follows its config](/reference/ai-router#the-guard-follows-its-config)): applies the guard
     * only when it is in place and its digest moved from the one the last run applied. The check and the admission are one writer
     * transaction, so a guard or forwarder run that ends meanwhile is in both.
     *
     * @return array{status: 'absent'|'current'|'pending', digest?: string, operation_id?: int}
     */
    public function ifChanged(Node $node, string $triggeredBy): array
    {
        // A first look without the writer skips the vault copies when nothing changed; the transaction decides.
        if ($this->look($node)['status'] !== 'pending') {
            return DB::transaction(fn (): array => $this->look($node));
        }
        $secrets = $this->guard->executionSecrets($node);

        return DB::transaction(function () use ($node, $secrets, $triggeredBy): array {
            $found = $this->look($node);

            return $found['status'] === 'pending' ? [...$found, 'operation_id' => $this->admit($node, false, $secrets, $triggeredBy, null)->id] : $found;
        });
    }

    /** @return array{status: 'absent'|'current'|'pending', digest?: string} */
    private function look(Node $node): array
    {
        if (! $this->guard->applied($node)) {
            return ['status' => 'absent'];
        }
        $digest = $this->guard->digest($this->guard->intent($node, $this->guard->forwarders($node), false));

        return ['status' => $this->guard->appliedDigest($node) === $digest ? 'current' : 'pending', 'digest' => $digest];
    }

    /** @param array<string, mixed> $secrets */
    private function admit(Node $node, bool $remove, array $secrets, ?string $triggeredBy, ?string $requestId): Operation
    {
        $intent = $this->guard->intent($node, $this->guard->forwarders($node), $remove);

        return $this->start->execute('ai_router.task_guard', 'node', $node->id, $node->id, [
            'intent' => $intent,
            'execution_secrets' => $secrets,
            // The Gateway keeps it; Swamp gets the intent and the secrets only.
            'guard_digest' => $this->guard->digest($intent),
        ], $triggeredBy, $requestId, lockKey: $this->guard->lock($node));
    }
}
