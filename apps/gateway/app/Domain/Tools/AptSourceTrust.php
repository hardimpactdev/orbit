<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Tool;
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

    /**
     * An admitted write may publish this definition: the record proves nothing until a write succeeds, and every origin the source may
     * now have on disk joins those its preferences restrict.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function suspect(int $nodeId, array $definition): AptRepository
    {
        $record = AptRepository::query()->firstOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => false, 'origins' => self::hosts($definition)]);
        $record->update(['verified' => false, 'origins' => self::origins($record, $definition)]);

        return $record;
    }

    /**
     * The origin hosts the source may have on disk, the definition's included: a failed change from one origin to another may have left
     * either, so the preferences of both keep every unrelated package at priority 1.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public static function origins(?AptRepository $record, array $definition): array
    {
        $hosts = [...($record === null ? [] : ($record->origins ?? self::hosts($record->definition))), ...self::hosts($definition)];
        $hosts = array_values(array_unique($hosts));
        sort($hosts, SORT_STRING);

        return $hosts;
    }

    /**
     * One apt source on a Node is one set of URIs and suites with one keyring: two differently named Orbit sources sharing a URI and
     * suite would give apt conflicting `Signed-By` values and stop every apt read on the Node.
     *
     * @param  array<string, mixed>  $definition
     * @param  iterable<array<string, mixed>>  $others  the definitions the Node has or will have under other names
     */
    public static function assertDistinct(array $definition, iterable $others): void
    {
        $entries = self::entries($definition);
        foreach ($others as $other) {
            if (self::name($other) !== self::name($definition) && array_intersect($entries, self::entries($other)) !== []) {
                throw new ResourceOperationException('tool.repository_conflict', 'Another apt source on this Node already uses the same URI and suite.', 409);
            }
        }
    }

    /**
     * The definitions of the Node's sources under every name: the recorded ones, which may still be on disk, and those enrolled Tools want.
     *
     * @return list<array<string, mixed>>
     */
    public static function nodeDefinitions(int $nodeId): array
    {
        $recorded = AptRepository::query()->where('node_id', $nodeId)->pluck('definition')->all();
        $desired = Tool::query()->where('node_id', $nodeId)->whereNotNull('desired_repository')->get()
            ->reject->withdrawn()->pluck('desired_repository')->all();

        return array_values(array_map(ValidatedData::object(...), array_filter([...$recorded, ...$desired], 'is_array')));
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
            ['definition' => $definition, 'verified' => true, 'origins' => self::hosts($definition)]);
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

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function hosts(array $definition): array
    {
        return array_values(array_unique(array_map(static fn (string $uri): string => strtolower((string) parse_url($uri, PHP_URL_HOST)),
            ValidatedData::stringList($definition['uris'] ?? []))));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function entries(array $definition): array
    {
        $entries = [];
        foreach (ValidatedData::stringList($definition['uris'] ?? []) as $uri) {
            foreach (ValidatedData::stringList($definition['suites'] ?? []) as $suite) {
                $entries[] = rtrim($uri, '/').' '.$suite;
            }
        }

        return $entries;
    }

    /** @param array<string, mixed> $definition */
    private static function name(array $definition): string
    {
        return ValidatedData::string($definition['name'] ?? null);
    }
}
