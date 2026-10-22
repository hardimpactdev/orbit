<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/** How machines install the recipe's PHP build (ADR 0216). */
final readonly class PhpPolicy
{
    /**
     * @param  string  $rollbackTool  The Ubuntu tool that repacks the installed build for a rollback.
     * @param  list<string>  $pcovProfiles  The profiles whose PHP CLI loads PCOV.
     * @param  list<string>  $opentelemetryProfiles  The profiles that install OpenTelemetry and load it in FPM only.
     */
    public function __construct(
        public string $rollbackTool = 'dpkg-repack',
        public array $pcovProfiles = [],
        public array $opentelemetryProfiles = [],
    ) {}
}
