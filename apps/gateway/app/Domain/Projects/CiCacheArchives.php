<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use InvalidArgumentException;

/**
 * The main caches of one successful CI run on the default branch: the tested commit and one ZIP archive per Composer
 * project, as the `Export main caches` step of `ci.yml` publishes them. The checkout validates their contents.
 */
final readonly class CiCacheArchives
{
    /** One archive, and all of them together, are read up to these sizes. */
    public const int MaxBytes = 16 * 1024 * 1024;

    public const int MaxTotalBytes = 32 * 1024 * 1024;

    public const int MaxArchives = 16;

    /** @param  list<string>  $archives */
    public function __construct(public string $commit, public int $runId, public array $archives)
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1 || $runId < 1 || $archives === [] || count($archives) > self::MaxArchives
            || array_sum(array_map(strlen(...), $archives)) > self::MaxTotalBytes) {
            throw new InvalidArgumentException('The CI main caches are invalid.');
        }
    }
}
