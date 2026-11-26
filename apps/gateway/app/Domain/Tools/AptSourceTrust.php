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
     * An admitted write may publish this definition: the record proves nothing until a write succeeds, and every URI and suite the
     * source may now have on disk joins those it already may have.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function suspect(int $nodeId, array $definition): AptRepository
    {
        $record = AptRepository::query()->firstOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => false, 'entries' => self::entries($definition)]);
        $record->update(['verified' => false, 'entries' => self::possible($record, $definition)]);

        return $record;
    }

    /**
     * The origin hosts the source may have on disk, the definition's included: a failed change from one origin to another may have left
     * either, so the preferences written while it is uncertain keep every unrelated package of both at priority 1.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public static function origins(?AptRepository $record, array $definition): array
    {
        $hosts = array_values(array_unique(array_map(static fn (string $entry): string => self::host(explode(' ', $entry, 2)[0]), self::possible($record, $definition))));
        sort($hosts, SORT_STRING);

        return $hosts;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> the record's possible `uri suite` entries and the definition's
     */
    private static function possible(?AptRepository $record, array $definition): array
    {
        $entries = array_values(array_unique([...($record === null ? [] : ($record->entries ?? self::entries($record->definition))), ...self::entries($definition)]));
        sort($entries, SORT_STRING);

        return $entries;
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
     * The definitions of the Node's sources under every name: each URI and suite a recorded source may still have on disk, even one an
     * uncertain write left or an adoption no longer names, and those enrolled Tools want.
     *
     * @return list<array<string, mixed>>
     */
    public static function nodeDefinitions(int $nodeId): array
    {
        $recorded = [];
        foreach (AptRepository::query()->where('node_id', $nodeId)->get() as $record) {
            foreach (self::possible($record, $record->definition) as $entry) {
                [$uri, $suite] = explode(' ', $entry, 2);
                $recorded[] = ['name' => $record->name, 'uris' => [$uri], 'suites' => [$suite]];
            }
        }
        $desired = Tool::query()->where('node_id', $nodeId)->whereNotNull('desired_repository')->get()
            ->reject->withdrawn()->pluck('desired_repository')->all();

        return [...$recorded, ...array_values(array_map(ValidatedData::object(...), array_filter($desired, 'is_array')))];
    }

    /**
     * The trust and possible entries of each named source as the Node records them before an admission suspects them, for the
     * Operation's input: a run that wrote nothing gives them back (restoreAdmitted).
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, array{verified: bool, entries: list<string>}>
     */
    public static function snapshot(int $nodeId, array $definitions): array
    {
        $snapshot = [];
        foreach (AptRepository::query()->where('node_id', $nodeId)->whereIn('name', array_map(self::name(...), $definitions))->get() as $record) {
            $snapshot[$record->name] = ['verified' => $record->verified, 'entries' => self::possible($record, $record->definition)];
        }

        return $snapshot;
    }

    /** @param list<array<string, mixed>> $definitions the definitions an admitted Operation may publish, remove or repin */
    public static function suspectAll(int $nodeId, array $definitions, int $operation): void
    {
        foreach ($definitions as $definition) {
            self::suspect($nodeId, $definition)->update(['suspected_by' => $operation]);
        }
    }

    /**
     * The definitions a convergence's plans may publish.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    public static function planned(array $plans): array
    {
        return array_values(array_map(ValidatedData::object(...), array_filter(array_column($plans, 'repository'), 'is_array')));
    }

    /**
     * Only the newest admitted Operation that may write a source changes its trust: an older receipt, late or not, describes a Node a
     * newer write may already have changed.
     */
    public static function current(AptRepository $record, int $operation): bool
    {
        return $record->suspected_by === null || $operation >= $record->suspected_by;
    }

    /**
     * An Operation that wrote nothing to its sources (it never started, was cancelled before its run, or its receipt skipped every source
     * write) gives each the trust and entries it was admitted with. A source it first created stays unproven, and a definition it
     * merely proposed gains nothing: the record still holds the definition it was admitted with.
     *
     * @param  array<string, mixed>  $snapshot  the Operation's `repository_admitted`
     */
    public static function restoreAdmitted(int $nodeId, array $snapshot, int $operation): void
    {
        foreach ($snapshot as $name => $admitted) {
            $record = AptRepository::query()->where('node_id', $nodeId)->where('name', $name)->first();
            if ($record !== null && is_array($admitted) && self::current($record, $operation)) {
                $record->update(['verified' => ($admitted['verified'] ?? false) === true, 'entries' => ValidatedData::stringList($admitted['entries'] ?? [])]);
            }
        }
    }

    /** @param array<string, mixed> $definition the definition Orbit's own successful write published; a newer admission keeps it unproven */
    public static function record(int $nodeId, array $definition, int $operation): AptRepository
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', self::name($definition))->first();
        if ($record !== null && ! self::current($record, $operation)) {
            return $record;
        }

        return AptRepository::query()->updateOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => true, 'entries' => self::entries($definition)]);
    }

    /** @param array<string, mixed> $definition the definition an adoption matched against the Node agent's fact */
    public static function adopt(int $nodeId, array $definition): AptRepository
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', self::name($definition))->first();
        if ($record !== null && self::verified($record, $definition)) {
            return $record;
        }

        // The fact may predate a write that published another definition: whatever that left stays a possible entry.
        return AptRepository::query()->updateOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => false, 'entries' => self::possible($record, $definition)]);
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

    /** The origin apt matches a source's `Pin: origin` against: the URI's host, an IPv6 literal without its brackets. */
    private static function host(string $uri): string
    {
        return trim(strtolower((string) parse_url($uri, PHP_URL_HOST)), '[]');
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
