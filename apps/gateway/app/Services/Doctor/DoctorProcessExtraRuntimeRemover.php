<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitName;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class DoctorProcessExtraRuntimeRemover
{
    public function __construct(
        private ProcessDockerRuntimeManager $processDockerRuntimeManager,
        private ProcessRuntimeDriverRegistry $processRuntimeDrivers,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function remove(Node $node, string $key, array $detail): ?array
    {
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? trim($detail['runtime_unit']) : '';

        if ($runtimeUnit === '') {
            return null;
        }

        if (
            ($detail['reason'] ?? null) === 'orphaned_managed_app_runtime'
            && str_starts_with($runtimeUnit, 'orbit-app-')
        ) {
            try {
                $removed = $this->processDockerRuntimeManager->remove($node, $runtimeUnit);
            } catch (Throwable $exception) {
                $removed = false;
                $detail['error'] = $exception->getMessage();
            }

            return $this->removalAction($node, $key, $runtimeUnit, $removed, $detail);
        }

        $runtime = is_string($detail['runtime'] ?? null) ? $detail['runtime'] : null;

        if ($runtime === 'systemd' && $this->isSafeSystemdUnit($runtimeUnit, $detail)) {
            try {
                $removed = $this->processRuntimeDrivers->for('systemd')->remove($node, $runtimeUnit);
            } catch (Throwable $exception) {
                $removed = false;
                $detail['error'] = $exception->getMessage();
            }

            return $this->removalAction($node, $key, $runtimeUnit, $removed, $detail);
        }

        if ($runtime === 'launchd' && $this->isSafeLaunchdUnit($runtimeUnit, $node, $detail)) {
            try {
                $removed = $this->processRuntimeDrivers->for('launchd')->remove($node, $runtimeUnit);
            } catch (Throwable $exception) {
                $removed = false;
                $detail['error'] = $exception->getMessage();
            }

            return $this->removalAction($node, $key, $runtimeUnit, $removed, $detail);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function isSafeSystemdUnit(string $runtimeUnit, array $detail): bool
    {
        if (! $this->isSafeManagedRuntimeUnitIdentity($runtimeUnit)) {
            return false;
        }

        $expectedPath = is_string($detail['expected_path'] ?? null) ? $detail['expected_path'] : null;
        $canonicalPath = '/etc/systemd/system/'.$runtimeUnit.'.service';

        if ($expectedPath !== null && $expectedPath !== $canonicalPath) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function isSafeLaunchdUnit(string $runtimeUnit, Node $node, array $detail): bool
    {
        // Launchd remove/render asserts ProcessRuntimeUnitName::isValid (max 64).
        // Do not accept legacy over-length identities that cannot be operated safely.
        if (! ProcessRuntimeUnitName::isValid($runtimeUnit) || ! str_starts_with($runtimeUnit, 'orbit_')) {
            return false;
        }

        $home = NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
        $label = 'dev.hardimpact.orbit.'.$runtimeUnit;
        $canonicalPath = $home.'/Library/LaunchAgents/'.$label.'.plist';
        $expectedPath = is_string($detail['expected_path'] ?? null) ? $detail['expected_path'] : null;
        $expected = is_string($detail['expected'] ?? null) ? $detail['expected'] : null;

        if ($expectedPath !== null && $expectedPath !== $canonicalPath) {
            return false;
        }

        if ($expected !== null && $expected !== $canonicalPath && $expected !== $runtimeUnit) {
            return false;
        }

        return true;
    }

    private function isSafeManagedRuntimeUnitIdentity(string $runtimeUnit): bool
    {
        // Orbit-owned systemd units are orbit_* identities. Allow legacy units
        // longer than the current 64-char bound so restore can remove them
        // after a rename/bound migration, but never absolute/relative paths.
        if (! str_starts_with($runtimeUnit, 'orbit_')) {
            return false;
        }

        if (str_contains($runtimeUnit, '/') || str_contains($runtimeUnit, "\0") || str_contains($runtimeUnit, '..')) {
            return false;
        }

        return (bool) preg_match('/\Aorbit_[a-z0-9](?:[a-z0-9_.-]{0,200}[a-z0-9])?\z/', $runtimeUnit);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function removalAction(
        Node $node,
        string $key,
        string $runtimeUnit,
        bool $removed,
        array $detail,
    ): array {
        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => $removed ? 'completed' : 'failed',
            'summary' => $removed
                ? "Removed orphaned managed process runtime {$runtimeUnit}."
                : "Failed to remove orphaned managed process runtime {$runtimeUnit}.",
            'details' => [
                ...$detail,
                'runtime_unit' => $runtimeUnit,
            ],
        ];
    }
}
