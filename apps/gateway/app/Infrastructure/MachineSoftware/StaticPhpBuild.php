<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * One static PHP build of the Instance runtime folder (docs/reference/compute-drivers.md#static-php-build): a PHP CLI and
 * FrankenPHP from one static-php-cli build, which link the extensions of the recipe's PHP version less `without` and less
 * the CLI-only extensions. The archive unpacks to `bin/php`, `bin/frankenphp` and `modules/<name>.so` for each CLI-only extension.
 */
final readonly class StaticPhpBuild
{
    /** Program name => path inside the unpacked archive. */
    public const array Bin = ['frankenphp' => 'bin/frankenphp', 'php' => 'bin/php'];

    /**
     * @param  list<string>  $extensions  The static extensions, lower case and sorted, as `php -m` lists them.
     * @param  list<string>  $cliExtensions  The shared extensions that only the PHP CLI loads, such as `pcov`.
     * @param  ?ArtifactPin  $artifact  Null until main has published the build and a reviewed commit records its SHA-256.
     */
    public function __construct(
        public string $version,
        public string $phpVersion,
        public string $frankenphp,
        public array $extensions,
        public array $cliExtensions,
        public string $build,
        public string $url,
        public ?ArtifactPin $artifact,
    ) {}
}
