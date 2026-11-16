<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** One CI Node of the runner view: its places, proof copies, resources and runners. */
final class CiNodeData extends Data
{
    /**
     * @param  list<CiProofCopyData>  $proof_copies
     * @param  list<CiRunnerData>  $runners
     */
    public function __construct(
        public int $node_id,
        public string $node_name,
        public CiPlacesData $places,
        public array $proof_copies,
        public ?CiNodeResourcesData $resources,
        public CiRunnerStateData $runner_state,
        public array $runners,
    ) {}
}
