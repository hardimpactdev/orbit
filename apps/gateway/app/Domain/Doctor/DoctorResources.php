<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorIssueData;

/** A typed selection owned by one family; string IDs are Schedule UUIDs, never selector protocols. */
final readonly class DoctorResources
{
    /** @param list<int|string> $ids */
    public function __construct(public DoctorResourceKind $kind, public array $ids = []) {}

    /**
     * @param  array<int, int|string>  $ids
     * @param  positive-int  $size
     * @return list<self>
     */
    public static function groups(array $ids, int $size = 20, DoctorResourceKind $kind = DoctorResourceKind::Rows): array
    {
        return array_map(static fn (array $group): self => new self($kind, $group), array_chunk($ids, $size));
    }

    /** @return list<DoctorIssueData> */
    public function failures(DoctorFamily $family, int $nodeId): array
    {
        $ids = in_array($this->kind, [DoctorResourceKind::Inventory, DoctorResourceKind::Environments], true) ? [null] : $this->ids;

        return array_map(static fn (int|string|null $id): DoctorIssueData => DoctorIssueData::fromInternal(
            $family, $family->value.'.inspection_failed', DoctorIssueKind::Unverifiable, $id, null,
            'Doctor collection did not complete on Node '.$nodeId.'.', 'completed probe', 'failed',
        ), $ids);
    }
}
