<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Support\ValidatedData;

/**
 * The rules every apt repository of a Node keeps (docs/reference/tools.md#apt-repositories). A repository is usable only after its own
 * add succeeded; one URI and suite belongs to one repository per Node, because apt stops every read when two sources give one entry
 * different `Signed-By` keyrings.
 */
final class AptRepositories
{
    /** The Node's repository a Tool write names: it must exist and be ready. */
    public static function usable(int $nodeId, string $name): AptRepository
    {
        $record = AptRepository::query()->where('node_id', $nodeId)->where('name', $name)->first()
            ?? throw new ResourceOperationException('tool.repository_missing', 'The Node has no apt repository with this name. Add it with tool:apt-repository-add.', 422);
        if (! $record->ready()) {
            throw new ResourceOperationException('tool.repository_not_ready', 'The apt repository is not ready. Run tool:apt-repository-add again to publish it.', 409);
        }

        return $record;
    }

    /**
     * In file mode the catalog defines each repository; the Node's record of that name must hold the same definition, or the write or
     * convergence would act on a source the catalog no longer describes.
     */
    public static function assertCatalog(ActiveToolPolicy $active, AptRepository $record): void
    {
        if ($active->mode === 'file' && ! self::same($active->repository($record->name), $record->definition)) {
            throw new ResourceOperationException('tool.repository_conflict', 'The catalog defines this repository differently from the Node: rename it in the catalog, or remove and add it.', 409);
        }
    }

    /**
     * Refuses a definition that shares a URI and suite with another repository of the Node.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function assertDistinct(int $nodeId, array $definition): void
    {
        $entries = self::entries($definition);
        foreach (AptRepository::query()->where('node_id', $nodeId)->where('name', '!=', ValidatedData::string($definition['name'] ?? null))->get() as $other) {
            if (array_intersect($entries, self::entries($other->definition)) !== []) {
                throw new ResourceOperationException('tool.repository_conflict', 'Another apt repository on this Node already uses the same URI and suite.', 409);
            }
        }
    }

    /**
     * Two catalog or request definitions must not share a URI and suite under different names.
     *
     * @param  list<array<string, mixed>>  $definitions
     */
    public static function assertDistinctAmong(array $definitions): void
    {
        $owners = [];
        foreach ($definitions as $definition) {
            foreach (self::entries($definition) as $entry) {
                if (isset($owners[$entry]) && $owners[$entry] !== $definition['name']) {
                    throw new ResourceOperationException('tool.repository_conflict', 'Two apt repositories use the same URI and suite.', 409);
                }
                $owners[$entry] = $definition['name'];
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $first
     * @param  array<string, mixed>|null  $second
     */
    public static function same(?array $first, ?array $second): bool
    {
        return self::canonical($first) === self::canonical($second);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> each `uri suite` the definition publishes
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
}
