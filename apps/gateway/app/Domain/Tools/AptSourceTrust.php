<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\AptRepository;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

/**
 * A Node's record of each signed apt source Orbit may have on disk, and whether that record still proves the files
 * (docs/reference/tools.md#source-trust).
 *
 * A record exists from the admission of the first write that may publish the source until a removal confirms both the source and its
 * preferences are gone, so a partial cleanup stays retryable. Admitting a write that may change the key, source or its removal clears
 * the proof; only Orbit's own successful write restores it. Adoption reads a Node agent fact that may predate a write, so it never does.
 */
final class AptSourceTrust
{
    /** @param array<string, mixed> $repository */
    public static function verified(?AptRepository $record, array $repository): bool
    {
        return $record !== null && $record->verified && ToolDefinition::sameRepository($record->definition, $repository);
    }

    /** @param array<string, mixed> $definition the definition an admitted write may publish */
    public static function suspect(int $nodeId, array $definition): AptRepository
    {
        $record = AptRepository::query()->firstOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => false]);
        $record->update(['verified' => false]);

        return $record;
    }

    /** @param list<array<string, mixed>> $plans the admitted convergence plans, which always publish their sources */
    public static function suspectPlans(int $nodeId, array $plans): void
    {
        foreach ($plans as $plan) {
            if (is_array($plan['repository'] ?? null)) {
                self::suspect($nodeId, ValidatedData::object($plan['repository']));
            }
        }
    }

    /**
     * A convergence whose Tools phase did not run wrote nothing: each source its plans captured as verified is verified again.
     *
     * @param  list<array<string, mixed>>  $plans
     */
    public static function restorePlans(int $nodeId, array $plans): void
    {
        foreach ($plans as $plan) {
            $repository = $plan['repository'] ?? null;
            if (is_array($repository) && ($plan['repository_verified'] ?? false) === true) {
                $record = AptRepository::query()->where('node_id', $nodeId)->where('name', self::name(ValidatedData::object($repository)))->first();
                if ($record !== null && ToolDefinition::sameRepository($record->definition, ValidatedData::object($repository))) {
                    $record->update(['verified' => true]);
                }
            }
        }
    }

    /** @param array<string, mixed> $definition a definition a run left on disk unchanged, with preferences as its owned members need */
    public static function prove(int $nodeId, array $definition): void
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', self::name($definition))->first();
        if ($record !== null && ToolDefinition::sameRepository($record->definition, $definition)) {
            $record->update(['verified' => true]);
        }
    }

    /** @param array<string, mixed> $definition the definition Orbit's own successful write published */
    public static function record(int $nodeId, array $definition): AptRepository
    {
        return AptRepository::query()->updateOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => true]);
    }

    /** @param array<string, mixed> $definition the definition an adoption matched against the Node agent's fact */
    public static function adopt(int $nodeId, array $definition): AptRepository
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', self::name($definition))->first();
        if ($record !== null && self::verified($record, $definition)) {
            return $record;
        }

        return AptRepository::query()->updateOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => false]);
    }

    /**
     * The sources Orbit may still have on disk that no Tool names, by name: a full convergence removes their files, and a phase that
     * did so forgets them. A colliding package name cannot hide one, because a source is its own cleanup route.
     *
     * @return list<string>
     */
    public static function orphans(int $nodeId): array
    {
        return ValidatedData::stringList(self::unnamed($nodeId)->orderBy('name')->pluck('name')->all());
    }

    /** @param list<string> $names the orphans an admitted convergence removed, forgotten only while still no Tool names them */
    public static function forgetOrphans(int $nodeId, array $names): void
    {
        if ($names !== []) {
            self::unnamed($nodeId)->whereIn('name', $names)->delete();
        }
    }

    /** @return Builder<AptRepository> */
    private static function unnamed(int $nodeId): Builder
    {
        return AptRepository::query()->where('node_id', $nodeId)
            ->whereNotExists(static fn ($query) => $query->from('tools')->where('tools.node_id', $nodeId)
                ->where(static fn ($named) => $named->whereColumn('tools.apt_repository_id', 'apt_repositories.id')
                    ->orWhereColumn('tools.desired_repository->name', 'apt_repositories.name')));
    }

    /** @param array<string, mixed> $definition */
    private static function name(array $definition): string
    {
        return ValidatedData::string($definition['name'] ?? null);
    }
}
