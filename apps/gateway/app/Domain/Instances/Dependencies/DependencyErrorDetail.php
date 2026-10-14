<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

final readonly class DependencyErrorDetail
{
    public static function for(string $code): string
    {
        return match ($code) {
            'dependencies.invalid_composer_input' => 'composer.json or composer.lock failed Composer graph validation',
            'dependencies.invalid_manifest' => 'composer.json or package.json must contain a JSON object with unique keys',
            'dependencies.invalid_bun_input' => 'package.json or bun.lock failed Bun graph validation',
            'dependencies.invalid_npm_input' => 'package.json or package-lock.json failed npm graph validation',
            'dependencies.invalid_pnpm_input' => 'package.json or pnpm-lock.yaml failed pnpm graph validation',
            'dependencies.stale_bun_lockfile' => 'bun.lock workspace dependencies do not match package.json',
            'dependencies.stale_npm_lockfile' => 'npm lockfile root dependencies do not match package.json',
            'dependencies.stale_pnpm_lockfile' => 'pnpm-lock.yaml importer dependencies do not match package.json',
            'dependencies.ambiguous_manager' => 'package.json declarations or collected manager files select different managers',
            'dependencies.unsupported_format' => 'selected dependency lockfile format or manager declaration is unsupported',
            'dependencies.unsupported_layout' => 'dependency files use an unsupported workspace or local dependency layout',
            'dependencies.incomplete_source' => 'selected manager requires both its manifest and lockfile',
            'dependencies.source_changed' => 'dependency source changed during collection',
            'dependencies.source_too_large' => 'dependency file collection exceeds the size limit',
            'dependencies.unreadable_source' => 'dependency file could not be read',
            'dependencies.unsafe_source' => 'dependency file failed collection path safety checks',
            'dependencies.invalid_collection' => 'dependency collection receipt failed validation',
            'dependencies.source_unavailable' => 'dependency source directory could not be collected',
            'dependencies.instance_unavailable' => 'Instance is no longer available for a dependency scan',
            'dependencies.operation_busy' => 'another Instance source operation holds the scan lock',
            'dependencies.persistence_failed' => 'dependency inventory could not be stored',
            default => 'dependency scan check failed',
        };
    }
}
