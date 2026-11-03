<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Data\Nodes\NodeAgentData;
use App\Domain\Doctor\DoctorFamilyStatus;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DoctorNodeReportData extends Data
{
    /**
     * @param  list<DoctorFamilyReportData>  $families
     * @param  ?NodeAgentData  $agent  the agent's liveness, printed first; while it is offline every finding may be outdated. Null for a Node without an observing agent.
     */
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public bool $healthy,
        public array $families,
        public ?NodeAgentData $agent = null,
    ) {}

    /** @param list<DoctorFamilyReportData> $families */
    public static function fromFamilies(int $nodeId, string $nodeName, array $families, ?NodeAgentData $agent = null): self
    {
        return new self(
            $nodeId,
            $nodeName,
            ! array_any(
                $families,
                static fn (DoctorFamilyReportData $family): bool => $family->status !== DoctorFamilyStatus::Healthy,
            ),
            $families,
            $agent,
        );
    }
}
