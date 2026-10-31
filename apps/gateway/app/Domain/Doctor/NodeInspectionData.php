<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use Illuminate\Support\Arr;
use UnexpectedValueException;

/**
 * @phpstan-type StoredInspection array{reachable: bool, platform: string|null, architecture: string|null, wireGuardAddressMatches: bool|null, agentBinaryExists: bool|null, agentUnitExists: bool|null, agentActive: bool|null, agentChecksumMatches: bool|null, agentSecretChecksum: string|null, workerState: string|null, diskFilesystems: list<array{location: string, availableKiB: int, sizeKiB: int, freeInodes: int|null, totalInodes: int|null}>}
 */
final readonly class NodeInspectionData
{
    public function __construct(
        public bool $reachable,
        public ?string $platform,
        public ?string $architecture,
        public ?bool $wireGuardAddressMatches,
        public ?bool $agentBinaryExists = null,
        public ?bool $agentUnitExists = null,
        public ?bool $agentActive = null,
        public ?bool $agentChecksumMatches = null,
        /** The SHA-256 hash of the agent secret file, or null when the file is absent (ADR 0155). */
        public ?string $agentSecretChecksum = null,
        /** @var list<NodeDiskFilesystemData> */
        public array $diskFilesystems = [],
        public ?string $workerState = null,
    ) {}

    /** @return StoredInspection */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'platform' => $this->platform,
            'architecture' => $this->architecture,
            'wireGuardAddressMatches' => $this->wireGuardAddressMatches,
            'agentBinaryExists' => $this->agentBinaryExists,
            'agentUnitExists' => $this->agentUnitExists,
            'agentActive' => $this->agentActive,
            'workerState' => $this->workerState,
            'agentChecksumMatches' => $this->agentChecksumMatches,
            'agentSecretChecksum' => $this->agentSecretChecksum,
            'diskFilesystems' => array_map(static fn (NodeDiskFilesystemData $disk): array => [
                'location' => $disk->location, 'availableKiB' => $disk->availableKiB, 'sizeKiB' => $disk->sizeKiB,
                'freeInodes' => $disk->freeInodes, 'totalInodes' => $disk->totalInodes,
            ], $this->diskFilesystems),
        ];
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $disks = [];
        foreach (Arr::array($stored, 'diskFilesystems') as $disk) {
            if (! is_array($disk)) {
                throw new UnexpectedValueException('Invalid cached Doctor filesystem observation.');
            }
            $disks[] = new NodeDiskFilesystemData(
                Arr::string($disk, 'location'), Arr::integer($disk, 'availableKiB'), Arr::integer($disk, 'sizeKiB'),
                $disk['freeInodes'] === null ? null : Arr::integer($disk, 'freeInodes'),
                $disk['totalInodes'] === null ? null : Arr::integer($disk, 'totalInodes'),
            );
        }

        return new self(
            Arr::boolean($stored, 'reachable'),
            $stored['platform'] === null ? null : Arr::string($stored, 'platform'),
            $stored['architecture'] === null ? null : Arr::string($stored, 'architecture'),
            self::nullableBoolean($stored, 'wireGuardAddressMatches'),
            self::nullableBoolean($stored, 'agentBinaryExists'),
            self::nullableBoolean($stored, 'agentUnitExists'),
            self::nullableBoolean($stored, 'agentActive'),
            self::nullableBoolean($stored, 'agentChecksumMatches'),
            $stored['agentSecretChecksum'] === null ? null : Arr::string($stored, 'agentSecretChecksum'),
            $disks,
            ($stored['workerState'] ?? null) === null ? null : Arr::string($stored, 'workerState'),
        );
    }

    /** @param array<array-key, mixed> $stored */
    private static function nullableBoolean(array $stored, string $key): ?bool
    {
        return $stored[$key] === null ? null : Arr::boolean($stored, $key);
    }
}
