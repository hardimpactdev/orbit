<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Support\ValidatedData;

/**
 * The Gateway's part of a skipped converge phase (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change): the Node's
 * record of phase digests. Swamp computes each digest from what the phases read, compares it with the record, and skips.
 */
final readonly class NodeConvergePhaseDigest
{
    /**
     * The converge intent as Swamp reads it: the captured intent and the digests the Node records.
     *
     * @param  array<string,mixed>  $intent
     * @param  array<string,string>  $recorded
     * @return array<string,mixed>
     */
    public function stamp(array $intent, array $recorded): array
    {
        return [...$intent, 'recorded_digests' => $recorded];
    }

    /**
     * The digests a converge compares with: none when the caller asks for `--full`, the Node has no record, or an agent command recorded
     * a failed agent pin since the record. That command took no admission lock, and the converge it asks for must install the agent again.
     *
     * @return array<string,string>
     */
    public function recorded(Node $node, bool $full): array
    {
        return $full || $node->agent_failed_pin !== null ? [] : array_map(ValidatedData::string(...), $node->converge_digests ?? []);
    }

    /**
     * The record a scoped converge (the base, a role subset or a release) holds at admission: only one that a full-scope `node:converge`
     * wrote. A role addition or role convergence records digests of its own phases; restoring them could skip a phase that the scoped
     * run's change makes necessary.
     *
     * @return array<string,string>
     */
    public function holdable(Node $node): array
    {
        return $node->converge_digests_full ? $this->recorded($node, false) : [];
    }

    /**
     * What a succeeded scoped converge records: its own digests read a partial intent that no full converge reads, so they would make
     * the next full converge run every phase. It keeps the record it held at admission instead, without each phase that reported a
     * change: that phase's record describes the Node before it. A receipt counts only the target's changes, so a network phase that
     * ran with a hub, which it may have changed, drops `network` too.
     *
     * @param  array<string,string>  $held
     * @param  array<array<string,mixed>>  $phases
     * @return array<string,string>
     */
    public function held(array $held, array $phases, bool $hub): array
    {
        $changed = array_map(static fn (array $phase): string => ValidatedData::string($phase['name']), array_filter($phases,
            static fn (array $phase): bool => $phase['changed'] !== 0 || ($hub && $phase['name'] === 'network' && $phase['status'] !== 'unchanged')));

        return array_diff_key($held, array_flip($changed));
    }

    /**
     * After a succeeded converge or role Operation: the digest of each phase in its scope, whether it ran or was unchanged. `$full` marks
     * a record of the full-scope converge phases, the only record a scoped converge holds.
     *
     * @param  array<string,mixed>  $digests
     */
    public function record(int $nodeId, array $digests, bool $full): void
    {
        Node::query()->whereKey($nodeId)->update(['converge_digests' => $digests === [] ? null : json_encode($digests, JSON_THROW_ON_ERROR),
            'converge_digests_full' => $full && $digests !== []]);
    }

    /** The admission of every Operation that takes the Node's admission lock: the next converge runs every phase. */
    public function clear(int $nodeId): void
    {
        Node::query()->whereKey($nodeId)->update(['converge_digests' => null, 'converge_digests_full' => false]);
    }
}
