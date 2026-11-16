<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The one rule of a skipped converge phase (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change). The Gateway owns
 * the phase input part of each digest and the Node's record; Swamp adds the secrets and the code, compares, and skips.
 */
final readonly class NodeConvergePhaseDigest
{
    /**
     * The converge intent as Swamp reads it: the captured intent with its phase input part and the digests the Node records.
     *
     * @param  array<string,mixed>  $intent
     * @param  array<string,string>  $recorded
     * @return array<string,mixed>
     */
    public function stamp(array $intent, Operation $operation, array $recorded): array
    {
        return [...$intent, 'phase_input' => $this->input($intent, $operation), 'recorded_digests' => $recorded];
    }

    /**
     * The phase input part: the SHA-256 of the intent with this Operation's ID replaced by a fixed mark, so two attempts with the same
     * recorded state have the same digest. Secret values are not in the intent; Swamp adds their digest.
     *
     * @param  array<string,mixed>  $intent
     */
    public function input(array $intent, Operation $operation): string
    {
        $json = json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) preg_replace('/operation-'.$operation->id.'(?![0-9])/', 'operation-#', $json));
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
     * After a succeeded converge: the digest of each phase in its scope, whether it ran or was unchanged.
     *
     * @param  array<string,mixed>  $digests
     */
    public function record(int $nodeId, array $digests): void
    {
        Node::query()->whereKey($nodeId)->update(['converge_digests' => $digests === [] ? null : json_encode($digests, JSON_THROW_ON_ERROR)]);
    }

    /** The admission of every Operation that takes the Node's admission lock: the next converge runs every phase. */
    public function clear(int $nodeId): void
    {
        Node::query()->whereKey($nodeId)->update(['converge_digests' => null]);
    }
}
