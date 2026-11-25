<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\AptRepository;
use App\Support\ValidatedData;

/**
 * Whether a Node's recorded signed apt source still proves what is on disk. An admitted write that may change the key,
 * source or its removal clears the proof; only a write that succeeds restores it. A cancelled, timed-out or unreadable
 * write therefore leaves the source unverified, and an older failure cannot outlive a later verified write.
 */
final class AptSourceTrust
{
    /** @param array<string, mixed> $repository */
    public static function verified(?AptRepository $record, array $repository): bool
    {
        return $record !== null && $record->verified && ToolDefinition::sameRepository($record->definition, $repository);
    }

    public static function suspect(int $nodeId, string $name): void
    {
        AptRepository::query()->where('node_id', $nodeId)->where('name', $name)->update(['verified' => false]);
    }

    /** @param list<array<string, mixed>> $plans the admitted convergence plans, which always publish their sources */
    public static function suspectPlans(int $nodeId, array $plans): void
    {
        foreach ($plans as $plan) {
            if (is_array($plan['repository'] ?? null)) {
                self::suspect($nodeId, ValidatedData::string($plan['repository']['name'] ?? null));
            }
        }
    }

    /** @param array<string, mixed> $definition the definition a successful write published or adoption observed */
    public static function record(int $nodeId, array $definition): AptRepository
    {
        return AptRepository::query()->updateOrCreate(['node_id' => $nodeId, 'name' => ValidatedData::string($definition['name'] ?? null)],
            ['definition' => $definition, 'verified' => true]);
    }
}
