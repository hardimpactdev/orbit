<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\DatabaseService;
use App\Services\ProvisionLogger;

final readonly class SetPhpVersion
{
    private const AVAILABLE_VERSIONS = ['8.5', '8.4', '8.3'];

    private const DEFAULT_VERSION = '8.5';

    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        // Determine PHP version
        $version = $context->phpVersion;

        if (! $version || ! in_array($version, self::AVAILABLE_VERSIONS, true)) {
            $version = $this->detectPhpVersionFromComposer($context->projectPath, $logger);
        }

        $logger->info("Setting PHP version to {$version}");

        // Write .php-version file
        $versionFile = "{$context->projectPath}/.php-version";
        file_put_contents($versionFile, "{$version}\n");
        $logger->log('Wrote .php-version file');

        // Update database
        app(DatabaseService::class)->setSitePhpVersion(
            $context->slug,
            $context->projectPath,
            $version
        );
        $logger->log('Updated database with PHP version');

        return StepResult::success(['phpVersion' => $version]);
    }

    private function detectPhpVersionFromComposer(string $projectPath, ProvisionLogger $logger): string
    {
        $composerPath = "{$projectPath}/composer.json";

        if (! file_exists($composerPath)) {
            $logger->log('No composer.json, using default PHP '.self::DEFAULT_VERSION);

            return self::DEFAULT_VERSION;
        }

        $content = file_get_contents($composerPath);
        if (! $content) {
            return self::DEFAULT_VERSION;
        }

        $composer = json_decode($content, true);
        $constraint = $composer['require']['php'] ?? null;

        if (! $constraint) {
            $logger->log('No PHP constraint in composer.json, using '.self::DEFAULT_VERSION);

            return self::DEFAULT_VERSION;
        }

        $version = $this->getRecommendedPhpVersion($constraint);
        $logger->log("Detected PHP version {$version} from constraint: {$constraint}");

        return $version;
    }

    private function getRecommendedPhpVersion(string $constraint): string
    {
        $constraint = trim($constraint);

        // Check for explicit upper bound that excludes versions
        if (preg_match("/<\s*(\d+)\.(\d+)/", $constraint, $matches)) {
            $maxMajor = (int) $matches[1];
            $maxMinor = (int) $matches[2];

            foreach (self::AVAILABLE_VERSIONS as $version) {
                [$major, $minor] = explode('.', $version);
                if ((int) $major < $maxMajor || ((int) $major === $maxMajor && (int) $minor < $maxMinor)) {
                    return $version;
                }
            }
        }

        // Check for tilde constraint ~8.x.y which locks to 8.x.*
        if (preg_match("/~\s*(\d+)\.(\d+)\./", $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // Check for wildcard constraint 8.x.* which locks to 8.x
        if (preg_match("/(\d+)\.(\d+)\.\*/", $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // For caret (^), greater-than (>=, >), or simple version constraints,
        // the latest version is compatible
        return self::DEFAULT_VERSION;
    }
}
