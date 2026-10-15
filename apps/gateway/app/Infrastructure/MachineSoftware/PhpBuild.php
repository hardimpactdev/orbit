<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/** One PHP version of the recipe: its exact Sury pins and available extensions. See ADR 0216. */
final readonly class PhpBuild
{
    /**
     * @param  string  $phpVersion  The upstream release that `PHP_VERSION` reports, such as 8.5.11.
     * @param  list<string>  $packages  The version's own pins and the shared pins, sorted.
     * @param  list<string>  $extensions  The build's modules, including FPM-only OpenTelemetry, lower case and sorted.
     */
    public function __construct(
        public string $version,
        public string $phpVersion,
        public array $packages,
        public array $extensions,
    ) {}

    /** @return list<string> */
    public function packagesFor(bool $opentelemetry): array
    {
        return $opentelemetry ? $this->packages : array_values(array_diff($this->packages, ["php{$this->version}-opentelemetry"]));
    }

    /** @return list<string> */
    public function cliExtensions(): array
    {
        return array_values(array_diff($this->extensions, ['opentelemetry']));
    }
}
