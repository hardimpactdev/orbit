<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use InvalidArgumentException;

/**
 * One static PHP build of the Instance runtime folder (docs/reference/compute-drivers.md#static-php-build): a PHP CLI and
 * FrankenPHP from one static-php-cli build, with the extensions of the recipe's PHP version less those a fully static
 * binary cannot load. The archive unpacks to `bin/php` and `bin/frankenphp`.
 */
final readonly class StaticPhpBuild
{
    /** Program name => path inside the unpacked archive. */
    public const array Bin = ['frankenphp' => 'bin/frankenphp', 'php' => 'bin/php'];

    /**
     * @param  list<string>  $extensions  Lower case and sorted, as `php -m` lists them.
     * @param  ?ArtifactPin  $artifact  Null until main has published the build and a reviewed commit records its SHA-256.
     */
    public function __construct(
        public string $version,
        public string $phpVersion,
        public string $frankenphp,
        public array $extensions,
        public string $build,
        public string $url,
        public ?ArtifactPin $artifact,
    ) {}

    public function published(): ArtifactPin
    {
        return $this->artifact ?? throw new InvalidArgumentException("The static PHP {$this->version} build [{$this->build}] is not published yet: its recipe pin has no SHA-256.");
    }
}
