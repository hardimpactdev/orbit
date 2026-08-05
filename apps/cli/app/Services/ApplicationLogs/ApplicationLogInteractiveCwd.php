<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Pure interpretation of interactive cwd inference results for application-log commands.
 */
final readonly class ApplicationLogInteractiveCwd
{
    public function __construct(
        private ApplicationLogInteractiveAppCwd $app = new ApplicationLogInteractiveAppCwd,
        private ApplicationLogInteractiveInstanceCwd $instance = new ApplicationLogInteractiveInstanceCwd,
    ) {}

    /**
     * @param  array<string, mixed>  $inferred
     * @return array{ok: true, target: array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}, requested: string}|array{ok: false, message: string, reason: string}
     */
    public function normalizeAppLog(array $inferred): array
    {
        return $this->app->normalize($inferred);
    }

    /**
     * @param  array<string, mixed>  $inferred
     * @return array{ok: true, selector: string}|array{ok: false, message: string, reason: string}
     */
    public function normalizeInstanceLog(array $inferred): array
    {
        return $this->instance->normalize($inferred);
    }
}
