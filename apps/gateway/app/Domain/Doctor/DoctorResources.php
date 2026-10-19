<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorIssueData;
use LogicException;

/** A typed row selection or Node check owned by one family. */
final readonly class DoctorResources
{
    public const string NodeCheckPrefix = 'node:';

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

    public static function nodeCheckId(int $nodeId, ?DoctorResourceKind $kind = null): string
    {
        $suffix = match ($kind) {
            null => '',
            DoctorResourceKind::Inventory => ':inventory',
            DoctorResourceKind::Environments => ':environments',
            default => throw new LogicException('Row checks use their resource IDs.'),
        };

        return self::NodeCheckPrefix.$nodeId.$suffix;
    }

    public static function rowId(DoctorResourceKind $kind, int|string $id): int|string
    {
        return match ($kind) {
            DoctorResourceKind::Attachments => 'attachment:'.$id,
            DoctorResourceKind::Connections => 'connection:'.$id,
            DoctorResourceKind::Rows => $id,
            default => throw new LogicException('Node checks use their Node check ID.'),
        };
    }

    public static function rowModelId(DoctorResourceKind $kind, int|string $id): ?int
    {
        $prefix = (string) self::rowId($kind, '');
        $value = str_starts_with((string) $id, $prefix) ? substr((string) $id, strlen($prefix)) : '';

        return ctype_digit($value) ? (int) $value : null;
    }

    public static function isNodeCheckId(int|string $id, int $nodeId): bool
    {
        return in_array($id, [self::nodeCheckId($nodeId), self::nodeCheckId($nodeId, DoctorResourceKind::Inventory), self::nodeCheckId($nodeId, DoctorResourceKind::Environments)], true);
    }

    /** @return list<DoctorIssueData> */
    public function failures(DoctorFamily $family, int $nodeId): array
    {
        $ids = in_array($this->kind, [DoctorResourceKind::Inventory, DoctorResourceKind::Environments], true) ? [self::nodeCheckId($nodeId, $this->kind)] : array_map(fn (int|string $id): int|string => self::rowId($this->kind, $id), $this->ids);

        return array_map(static fn (int|string|null $id): DoctorIssueData => DoctorIssueData::fromInternal(
            $family, $family->value.'.inspection_failed', DoctorIssueKind::Unverifiable, $id, null,
            'Doctor collection did not complete on Node '.$nodeId.'.', 'completed probe', 'failed',
        ), $ids);
    }
}
