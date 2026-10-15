<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/** One PHP version of the recipe: its exact Sury pins and the extensions its CLI loads. See ADR 0215. */
final readonly class PhpBuild
{
    /**
     * @param  string  $phpVersion  The upstream release that `PHP_VERSION` reports, such as 8.5.11.
     * @param  list<string>  $packages  The version's own pins and the shared pins, sorted.
     * @param  list<string>  $extensions  `php -m` of the CLI, lower case and sorted.
     */
    public function __construct(
        public string $version,
        public string $phpVersion,
        public array $packages,
        public array $extensions,
    ) {}
}
