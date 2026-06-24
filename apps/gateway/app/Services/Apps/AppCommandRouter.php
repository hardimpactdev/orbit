<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\AppRuntimeUserResolver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;

final readonly class AppCommandRouter
{
    public function __construct(
        private AppRuntimeUserResolver $appRuntimeUser = new AppRuntimeUser,
    ) {}

    /**
     * Route PHP, Composer, and Artisan commands through Orbit's version-matched
     * host PHP toolchain. Non-PHP commands run as provided in the app path.
     *
     * @param  array<string, string>  $environment
     */
    public function route(App $app, string $command, array $environment = []): string
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return $command;
        }

        if (! $this->usesPhpTools($command)) {
            return $command;
        }

        return $this->wrapForHost($app, $command, $environment);
    }

    public function usesPhpTools(string $command): bool
    {
        $normalized = preg_replace('/[\'"].*?[\'"]/', '', $command);

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php-fpm\b/', (string) $normalized) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php\d+\.\d+-fpm\b/', (string) $normalized) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php\s/', (string) $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*composer\s/', (string) $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*artisan\b/', (string) $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function wrapForHost(App $app, string $command, array $environment): string
    {
        $appPath = rtrim((string) $app->path, '/');
        $phpVersion = $app->php_version;
        $runtimeUser = $this->appRuntimeUser->forApp($app);
        $envPrefix = '';

        foreach ($environment as $key => $value) {
            $envPrefix .= "{$key}=".escapeshellarg($value).' ';
        }

        $inner = 'cd '.escapeshellarg($appPath)
            .' && PATH=/opt/orbit/php/'.escapeshellarg($phpVersion).'/bin:$PATH '
            .$envPrefix
            .$command;

        return implode(' ', array_map(escapeshellarg(...), ['sudo', '-u', $runtimeUser, '-H', 'bash', '-lc', $inner]));
    }
}
