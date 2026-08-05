<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Interactive cwd inference for application-log commands from visible owned paths.
 */
final readonly class ApplicationLogCwdInference
{
    public function __construct(
        private ApplicationLogWorkspacePathTarget $workspacePathTarget = new ApplicationLogWorkspacePathTarget,
        private ApplicationLogInstanceInventory $instances = new ApplicationLogInstanceInventory,
        private ApplicationLogPathAncestorMatcher $paths = new ApplicationLogPathAncestorMatcher,
    ) {}

    /**
     * Prefer a resolve-by-path workspace, then a unique instance path ancestor.
     *
     * @param  array<string, mixed>|null  $workspaceData  success.data from resolve-by-path
     * @param  array<string, mixed>  $instancesData  success.data from GET /api/instances
     * @return array{type: 'workspace', workspace: string, instance: string}|array{type: 'instance', selector: string}|array{error: string, reason: string}
     */
    public function forAppLog(?array $workspaceData, array $instancesData, string $cwd): array
    {
        $workspace = $this->workspacePathTarget->fromResolveByPathData($workspaceData);

        if ($workspace !== null) {
            return $workspace;
        }

        return $this->forInstanceLog($instancesData, $cwd);
    }

    /**
     * Exactly one visible instance path that is an ancestor of cwd.
     *
     * @param  array<string, mixed>  $instancesData  success.data from GET /api/instances
     * @return array{type: 'instance', selector: string}|array{error: string, reason: string}
     */
    public function forInstanceLog(array $instancesData, string $cwd): array
    {
        $match = $this->paths->uniqueAncestorMatch($cwd, $this->instances->pathEntries($instancesData));

        if ($match['ok'] === true) {
            return [
                'type' => 'instance',
                'selector' => $match['selector'],
            ];
        }

        return [
            'error' => $match['reason'] === 'cwd_target_ambiguous'
                ? 'Multiple visible instances match the current directory path.'
                : 'No unambiguous instance target could be inferred from the current directory.',
            'reason' => $match['reason'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $workspaceData
     * @return array{type: 'workspace', workspace: string, instance: string}|array{error: string, reason: string}
     */
    public function forWorkspaceLog(?array $workspaceData): array
    {
        $workspace = $this->workspacePathTarget->fromResolveByPathData($workspaceData);

        if ($workspace !== null) {
            return $workspace;
        }

        return [
            'error' => 'No unambiguous workspace target could be inferred from the current directory.',
            'reason' => 'cwd_target_missing',
        ];
    }
}
