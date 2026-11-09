<?php

declare(strict_types=1);

namespace App\Infrastructure\Proof;

use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofObserverRelease;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;

/** Release admission runs in the trusted Gateway, before a proof VM is allocated. */
final readonly class PublishedProofObserverRelease implements ProofObserverRelease
{
    public function __construct(private GatewayReleaseAgentDelivery $delivery, private ProofCommitSource $source) {}

    public function forCommit(string $commit): string
    {
        $pin = $this->source->file($commit, GatewayReleaseAgentDelivery::Pin)
            ?? throw new ResourceOperationException('proof.agent_pin_missing', 'The proof commit has no published observer pin.', 422);

        return $this->delivery->verifyPin($pin, $commit);
    }

    /** `orbit proof` has no candidate revision. A changed pin must be pushed and proved with `proof:run`. */
    public function forGateway(): string
    {
        $pin = file_get_contents(base_path('resources/agent/frozen-manifest.json'));
        if ($pin === false) {
            throw new ResourceOperationException('proof.agent_pin_missing', 'The Gateway has no published observer pin.', 503);
        }

        return $this->delivery->verifyPin($pin, 'gateway');
    }
}
