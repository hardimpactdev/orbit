<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

/**
 * A Node's record of each signed apt source Orbit may have on disk, and whether that record still proves the files
 * (docs/reference/tools.md#source-trust).
 *
 * A record exists from the admission of the first write that may publish the source until a removal confirms both the source and its
 * preferences are gone, so a partial cleanup stays retryable. Each admitted Operation that may write it is an open writer until its end
 * settles it (proved, uncertain or unwritten); the newest settled end decides the trust, for the definition it proved. Adoption reads a
 * Node agent fact that may predate a write, so it never proves.
 */
final class AptSourceTrust
{
    /** @param array<string, mixed> $repository */
    public static function verified(?AptRepository $record, array $repository): bool
    {
        return $record !== null && $record->verified && ToolDefinition::sameRepository($record->definition, $repository);
    }

    /**
     * The Node's record of a source, created for the first admission that may publish it (unproven, nothing settled yet): the cleanup
     * handle an Operation carries.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function handle(int $nodeId, array $definition): AptRepository
    {
        return AptRepository::query()->firstOrCreate(['node_id' => $nodeId, 'name' => self::name($definition)],
            ['definition' => $definition, 'verified' => false, 'entries' => self::entries($definition), 'writers' => [],
                'settled' => ['verified' => false, 'definition' => $definition, 'entries' => [], 'operation' => 0]]);
    }

    /**
     * An admitted Operation may write these sources: each becomes an open writer, so the source proves nothing until a newer end
     * settles it, and every URI and suite it may publish is a possible entry.
     *
     * @param  list<array<string, mixed>>  $definitions
     */
    public static function claim(int $nodeId, array $definitions, int $operation): void
    {
        foreach ($definitions as $definition) {
            $record = self::handle($nodeId, $definition);
            $record->settled = self::settled($record);
            $writers = $record->writers ?? [];
            $writers[$operation] = self::entries($definition);
            $record->writers = $writers;
            self::recompute($record);
        }
    }

    /**
     * How an Operation's work on a source ended:
     * - `proved`: Orbit's own write published `$definition` (a successful write, a repin, a finished convergence phase);
     * - `uncertain`: it may have written (a failure after a source write, a partial phase, an unreadable or uncertain end);
     * - `unwritten`: it wrote nothing (it never ran, or its receipt skipped every source write).
     * Only the newest end settles the source: an older end, late or not, changes nothing a newer one settled (docs/reference/tools.md#source-trust).
     *
     * @param  array<string, mixed>|null  $definition
     */
    public static function settle(int $nodeId, string $name, int $operation, string $outcome, ?array $definition = null): void
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', $name)->first();
        if ($record === null) {
            return;
        }
        $writers = $record->writers ?? [];
        $wrote = $writers[$operation] ?? ($definition === null ? [] : self::entries($definition));
        unset($writers[$operation]);
        $record->writers = $writers;
        $settled = self::settled($record);
        $record->settled = $settled;
        if ($outcome !== 'unwritten' && $operation > $settled['operation']) {
            $record->settled = $outcome === 'proved' && $definition !== null
                ? ['verified' => true, 'definition' => $definition, 'entries' => self::entries($definition), 'operation' => $operation]
                : [...$settled, 'verified' => false, 'entries' => self::sorted([...$settled['entries'], ...ValidatedData::stringList($wrote)]), 'operation' => $operation];
            if ($outcome === 'proved' && $definition !== null) {
                $record->definition = $definition;
            }
        }
        self::recompute($record);
    }

    /**
     * Settles each source a convergence's plans named.
     *
     * @param  list<array<string, mixed>>  $plans
     */
    public static function settlePlans(int $nodeId, array $plans, int $operation, string $outcome): void
    {
        foreach (self::planned($plans) as $definition) {
            self::settle($nodeId, self::name($definition), $operation, $outcome, $definition);
        }
    }

    /**
     * A last member's removal confirmed the source and its preferences gone: the record goes, unless a newer Operation may have
     * published the source since or a Tool links it again.
     */
    public static function removed(int $recordId, int $operation): void
    {
        $record = AptRepository::query()->find($recordId);
        if ($record === null) {
            return;
        }
        $newer = array_filter(array_keys($record->writers ?? []), static fn (int|string $writer): bool => (int) $writer > $operation) !== [];
        if (! $newer && $operation >= self::settled($record)['operation'] && ! Tool::query()->where('apt_repository_id', $record->id)->exists()) {
            $record->delete();

            return;
        }
        self::settle($record->node_id, $record->name, $operation, 'uncertain');
    }

    /**
     * Trust holds only when the newest settled end proved the definition the record holds and no newer writer is open. An older open
     * writer, a run lost past its hold, cannot outrank a newer proof. Every entry a settled end or an open writer may have published stays
     * possible.
     */
    private static function recompute(AptRepository $record): void
    {
        $settled = self::settled($record);
        $writers = $record->writers ?? [];
        $newer = array_filter(array_keys($writers), static fn (int|string $writer): bool => (int) $writer > $settled['operation']) !== [];
        $record->verified = $settled['verified'] && ! $newer && ToolDefinition::sameRepository($settled['definition'], $record->definition);
        $record->entries = self::sorted([...$settled['entries'], ...self::entries($record->definition), ...array_merge(...array_values(array_map(ValidatedData::stringList(...), $writers)) ?: [[]])]);
        $record->save();
    }

    /**
     * The newest settled end; a record nothing settled yet (one the upgrade or an earlier write recorded) is settled as it stands.
     *
     * @return array{verified: bool, definition: array<string, mixed>, entries: list<string>, operation: int}
     */
    private static function settled(AptRepository $record): array
    {
        $settled = $record->settled ?? ['verified' => $record->verified, 'definition' => $record->definition,
            'entries' => $record->entries ?? self::entries($record->definition), 'operation' => 0];

        return ['verified' => ($settled['verified'] ?? false) === true, 'definition' => is_array($settled['definition'] ?? null) ? $settled['definition'] : $record->definition,
            'entries' => ValidatedData::stringList($settled['entries'] ?? []), 'operation' => is_int($settled['operation'] ?? null) ? $settled['operation'] : 0];
    }

    /**
     * @param  list<string>  $entries
     * @return list<string>
     */
    private static function sorted(array $entries): array
    {
        $entries = array_values(array_unique($entries));
        sort($entries, SORT_STRING);

        return $entries;
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
     * An adoption matched this definition against the Node agent's fact, which may predate a write: the record names it, unproven, and
     * every entry earlier work may have left stays possible. It is a newer end than any Operation admitted before it.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function adopt(int $nodeId, array $definition): AptRepository
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', self::name($definition))->first();
        if ($record !== null && self::verified($record, $definition)) {
            return $record;
        }
        $record ??= self::handle($nodeId, $definition);
        $settled = self::settled($record);
        $record->definition = $definition;
        $record->settled = ['verified' => false, 'definition' => $definition, 'entries' => self::sorted([...$settled['entries'], ...self::possible($record, $definition)]),
            'operation' => max($settled['operation'], ValidatedData::integer(Operation::query()->max('id') ?? 0))];
        self::recompute($record);

        return $record;
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
