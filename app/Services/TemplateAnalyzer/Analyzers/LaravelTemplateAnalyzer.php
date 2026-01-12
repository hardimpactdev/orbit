<?php

declare(strict_types=1);

namespace App\Services\TemplateAnalyzer\Analyzers;

use App\Services\TemplateAnalyzer\Contracts\TemplateAnalyzerInterface;
use App\Services\TemplateAnalyzer\EnvParser;
use App\Services\TemplateAnalyzer\TemplateAnalysisResult;
use Illuminate\Support\Facades\Http;

/**
 * Analyzer for Laravel templates.
 *
 * Detects Laravel projects by presence of artisan file and Laravel dependencies.
 * Extracts driver configurations from .env.example.
 */
final readonly class LaravelTemplateAnalyzer implements TemplateAnalyzerInterface
{
    /**
     * Laravel .env keys that map to our driver configuration.
     */
    private const ENV_KEY_MAP = [
        'DB_CONNECTION' => 'db_driver',
        'SESSION_DRIVER' => 'session_driver',
        'CACHE_STORE' => 'cache_driver',
        'CACHE_DRIVER' => 'cache_driver', // Legacy key, CACHE_STORE takes precedence
        'QUEUE_CONNECTION' => 'queue_driver',
    ];

    public function __construct(
        private EnvParser $envParser,
    ) {}

    public function supports(array $repoContents): bool
    {
        // Laravel projects must have an artisan file
        $hasArtisan = in_array('artisan', $repoContents, true);

        if (! $hasArtisan) {
            return false;
        }

        // Should also have composer.json (we'll verify Laravel dependency in analyze)
        return in_array('composer.json', $repoContents, true);
    }

    public function getType(): string
    {
        return 'laravel';
    }

    public function analyze(string $repo, string $branch): array
    {
        $drivers = [
            'db_driver' => null,
            'session_driver' => null,
            'cache_driver' => null,
            'queue_driver' => null,
        ];

        $metadata = [
            'framework' => 'laravel',
            'framework_version' => null,
            'php_version' => null,
        ];

        // Fetch .env.example
        $envContent = $this->fetchFile($repo, $branch, '.env.example');
        if ($envContent !== null) {
            $drivers = $this->parseEnvDrivers($envContent);
        }

        // Fetch composer.json for additional metadata
        $composerContent = $this->fetchFile($repo, $branch, 'composer.json');
        if ($composerContent !== null) {
            $composerData = json_decode($composerContent, true);
            if (is_array($composerData)) {
                $metadata = array_merge($metadata, $this->extractComposerMetadata($composerData));
            }
        }

        return TemplateAnalysisResult::laravel($drivers, $metadata)->toArray();
    }

    /**
     * Parse .env content and extract driver configurations.
     *
     * @return array<string, string|null>
     */
    private function parseEnvDrivers(string $envContent): array
    {
        $envVars = $this->envParser->parse($envContent);

        $drivers = [
            'db_driver' => null,
            'session_driver' => null,
            'cache_driver' => null,
            'queue_driver' => null,
        ];

        // Map env vars to our driver keys
        // Note: CACHE_STORE takes precedence over CACHE_DRIVER (newer Laravel convention)
        foreach (self::ENV_KEY_MAP as $envKey => $driverKey) {
            if (! isset($envVars[$envKey])) {
                continue;
            }
            if ($envVars[$envKey] === '') {
                continue;
            }
            // Only set if not already set (CACHE_STORE before CACHE_DRIVER)
            if ($drivers[$driverKey] !== null && $envKey !== 'CACHE_STORE') {
                continue;
            }
            $drivers[$driverKey] = $this->normalizeDriverValue($driverKey, $envVars[$envKey]);
        }

        return $drivers;
    }

    /**
     * Normalize driver values to our standard format.
     */
    private function normalizeDriverValue(string $driverKey, string $value): string
    {
        // Handle common aliases
        $aliases = [
            'db_driver' => [
                'mysql' => 'mysql',
                'pgsql' => 'pgsql',
                'postgres' => 'pgsql',
                'postgresql' => 'pgsql',
                'sqlite' => 'sqlite',
                'sqlsrv' => 'sqlsrv',
            ],
            'session_driver' => [
                'file' => 'file',
                'cookie' => 'cookie',
                'database' => 'database',
                'redis' => 'redis',
                'memcached' => 'memcached',
                'array' => 'array',
            ],
            'cache_driver' => [
                'file' => 'file',
                'database' => 'database',
                'redis' => 'redis',
                'memcached' => 'memcached',
                'array' => 'array',
                'apc' => 'apc',
                'dynamodb' => 'dynamodb',
            ],
            'queue_driver' => [
                'sync' => 'sync',
                'database' => 'database',
                'redis' => 'redis',
                'beanstalkd' => 'beanstalkd',
                'sqs' => 'sqs',
            ],
        ];

        $normalized = strtolower(trim($value));

        return $aliases[$driverKey][$normalized] ?? $normalized;
    }

    /**
     * Extract metadata from composer.json.
     *
     * @param  array<string, mixed>  $composer
     * @return array<string, mixed>
     */
    private function extractComposerMetadata(array $composer): array
    {
        $metadata = [];

        // Extract PHP version requirement
        $phpRequire = $composer['require']['php'] ?? null;
        if ($phpRequire !== null) {
            $metadata['php_version'] = $this->parseVersionConstraint($phpRequire);
        }

        // Extract Laravel version
        $laravelRequire = $composer['require']['laravel/framework'] ?? null;
        if ($laravelRequire !== null) {
            $metadata['framework_version'] = $this->parseVersionConstraint($laravelRequire);
        }

        // Check for common Laravel packages
        $metadata['packages'] = $this->detectCommonPackages($composer['require'] ?? []);

        return $metadata;
    }

    /**
     * Parse a composer version constraint to extract a readable version.
     */
    private function parseVersionConstraint(string $constraint): string
    {
        // Remove constraint operators
        $version = preg_replace('/^[\^~>=<|]+/', '', $constraint);

        // Take first version if multiple
        if (str_contains($version ?? '', '|')) {
            $version = explode('|', $version ?? '')[0];
        }

        return trim($version ?? $constraint);
    }

    /**
     * Detect common Laravel packages for context.
     *
     * @param  array<string, string>  $require
     * @return array<string>
     */
    private function detectCommonPackages(array $require): array
    {
        $knownPackages = [
            'laravel/sanctum' => 'Sanctum (API auth)',
            'laravel/breeze' => 'Breeze (starter kit)',
            'laravel/jetstream' => 'Jetstream (starter kit)',
            'laravel/horizon' => 'Horizon (Redis queues)',
            'laravel/telescope' => 'Telescope (debugging)',
            'laravel/reverb' => 'Reverb (WebSockets)',
            'inertiajs/inertia-laravel' => 'Inertia.js',
            'livewire/livewire' => 'Livewire',
        ];

        $detected = [];
        foreach ($knownPackages as $package => $label) {
            if (isset($require[$package])) {
                $detected[] = $label;
            }
        }

        return $detected;
    }

    /**
     * Fetch a file from GitHub.
     */
    private function fetchFile(string $repo, string $branch, string $path): ?string
    {
        $url = "https://raw.githubusercontent.com/{$repo}/{$branch}/{$path}";

        $response = Http::timeout(10)->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }
}
