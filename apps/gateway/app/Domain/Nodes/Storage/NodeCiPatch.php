<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeCiData;
use App\Domain\Shared\ResourceOperationException;

/** A `ci` patch: it changes only the members it names (docs/reference/node-settings.md#ci-places). */
final readonly class NodeCiPatch
{
    public function __construct(
        public ?int $places = null,
        public ?bool $proofOnly = null,
    ) {}

    /** The stored setting with this patch applied. The first setting of a Node must name `places`. */
    public function over(?NodeCiData $stored): NodeCiData
    {
        $places = $this->places ?? $stored->places
            ?? throw new ResourceOperationException('node.settings_invalid', 'The first ci setting of a Node must name places.');

        return new NodeCiData($places, $this->proofOnly ?? $stored->proof_only ?? false);
    }

    /** @return array{places?:int,proof_only?:bool} */
    public function toArray(): array
    {
        return array_filter(['places' => $this->places, 'proof_only' => $this->proofOnly], static fn (int|bool|null $member): bool => $member !== null);
    }
}
