<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

/** One request's reads of dependency resources. All reads of one reader share one serve connection, which `close()` ends. */
interface SwampDependencyReader
{
    /** Returns JSON content from the native resource. */
    public function read(string $dataName, ?int $version, int $maxBytes): string;

    /**
     * The latest content of each named resource, read on the connection an earlier `read()` of this reader opened, never on a new one.
     * A resource that serve answered as missing or invalid maps to null. One not answered in time, or lost with the connection, is
     * left out. Without an open connection the answer is empty.
     *
     * @param  list<string>  $dataNames
     * @return array<string, string|null>
     */
    public function readLatest(array $dataNames, int $maxBytes): array;

    public function close(): void;
}
