<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Contracts\RemoteShell;
use App\Enums\DriftKind;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Security\UnattendedUpgradesInstaller;
use JsonException;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;
use Throwable;

final readonly class UnattendedUpgradesDriver implements UpdateDriver
{
    private UnattendedUpgradesAptConfig $config;

    private UnattendedUpgradesInstaller $installer;

    public function __construct(
        private RemoteShell $remoteShell,
        ?UnattendedUpgradesAptConfig $config = null,
        ?UnattendedUpgradesInstaller $installer = null,
        private ?RemoteLocalExecutor $localExecutor = null,
    ) {
        $this->config = $config ?? new UnattendedUpgradesAptConfig;
        $this->installer = $installer ?? new UnattendedUpgradesInstaller;
    }

    public function key(): string
    {
        return 'unattended-upgrades';
    }

    public function supportedTargets(): array
    {
        return [
            new UpdateDriverTarget('node', 'ubuntu_24-04', 'managed-server-node'),
            new UpdateDriverTarget('node', 'ubuntu_26-04', 'managed-server-node'),
        ];
    }

    public function supports(UpdateTarget $target): bool
    {
        return (
            $target->family === 'node'
            && in_array($target->platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)
            && $target->scope === 'managed-server-node'
        );
    }

    public function probe(UpdateTarget $target): UpdatePostureSnapshot
    {
        try {
            $result = $this->localExecutor()->runInternal(
                node: $target->node,
                commandName: 'internal:unattended-upgrades:probe',
                arguments: [
                    $this->config->autoUpgradesSha256(),
                    $this->config->unattendedUpgradesSha256(),
                ],
                transportOptions: [
                    'timeout' => 120,
                    'throw' => false,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'unattended-upgrades.probe',
                    ],
                ],
            );
        } catch (Throwable $throwable) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue([
                    'exception' => $throwable->getMessage(),
                ]),
            ]);
        }

        if (! $result->successful()) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue([
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ]),
            ]);
        }

        $facts = $this->successData($result->stdout);

        if ($facts === []) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue([
                    'stdout' => trim($result->stdout),
                    'stderr' => trim($result->stderr),
                ]),
            ]);
        }

        return new UpdatePostureSnapshot($this->key(), $this->issuesFromFacts($facts));
    }

    public function apply(UpdateTarget $target): UpdateApplyResult
    {
        $installReport = $this->installer->installFor($target->node, $this->remoteShell);

        if (! $installReport->successful) {
            return new UpdateApplyResult(
                driver: $this->key(),
                status: 'failed',
                summary: $installReport->summary,
                detail: $installReport->details,
            );
        }

        $result = $this->localExecutor()->runInternal(
            node: $target->node,
            commandName: 'internal:unattended-upgrades:apply',
            transportOptions: [
                'timeout' => 900,
                'throw' => false,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'unattended-upgrades.apply',
                ],
            ],
        );

        if (! $result->successful()) {
            return new UpdateApplyResult(
                driver: $this->key(),
                status: 'failed',
                summary: 'Failed to run unattended-upgrades.',
                detail: [
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
            );
        }

        return new UpdateApplyResult(
            driver: $this->key(),
            status: 'completed',
            summary: 'Applied unattended security upgrades.',
            detail: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<UpdatePostureIssue>
     */
    private function issuesFromFacts(array $facts): array
    {
        $issues = [];
        $installed = ($facts['installed'] ?? false) === true;
        $autoExists = array_key_exists('auto_exists', $facts) ? $facts['auto_exists'] === true : true;
        $unattendedExists = array_key_exists('unattended_exists', $facts) ? $facts['unattended_exists'] === true : true;
        $autoHashOk = ($facts['auto_hash_ok'] ?? false) === true;
        $unattendedHashOk = ($facts['unattended_hash_ok'] ?? false) === true;

        if (! $installed || ! $autoExists || ! $unattendedExists) {
            $issues[] = $this->issue(
                code: 'node.updates_config_missing',
                kind: DriftKind::Missing,
                summary: 'This node is missing unattended-upgrades or Orbit apt auto-upgrade configuration.',
                restorable: true,
                detail: [
                    'installed' => $installed,
                    'auto_exists' => $autoExists,
                    'unattended_exists' => $unattendedExists,
                ],
            );

            return $issues;
        }

        if (! $autoHashOk || ! $unattendedHashOk) {
            $issues[] = $this->issue(
                code: 'node.updates_config_mismatch',
                kind: DriftKind::Divergent,
                summary: 'This node has unattended-upgrades configuration that differs from Orbit policy.',
                restorable: true,
                detail: [
                    'auto_hash_ok' => $autoHashOk,
                    'unattended_hash_ok' => $unattendedHashOk,
                ],
            );
        }

        $dryRunExit = is_numeric($facts['dry_run_exit'] ?? null) ? (int) $facts['dry_run_exit'] : null;

        if ($dryRunExit !== null && $dryRunExit !== 0) {
            $issues[] = $this->issue(
                code: 'node.updates_dry_run_failed',
                kind: DriftKind::Unverifiable,
                summary: 'This node cannot complete an unattended-upgrades dry run.',
                restorable: true,
                detail: [
                    'dry_run_exit' => $dryRunExit,
                ],
            );
        }

        $lastRunStatus = is_string($facts['last_run_status'] ?? null) ? $facts['last_run_status'] : 'unknown';

        if ($lastRunStatus === 'failed') {
            $issues[] = $this->issue(
                code: 'node.updates_last_run_failed',
                kind: DriftKind::Divergent,
                summary: 'This node has a recent failed unattended-upgrades run.',
                restorable: true,
                detail: [
                    'last_run_status' => $lastRunStatus,
                ],
            );
        }

        if (($facts['reboot_required'] ?? false) === true) {
            $issues[] = $this->issue(
                code: 'node.updates_reboot_required',
                kind: DriftKind::Divergent,
                summary: 'This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.',
                restorable: false,
                detail: [
                    'reboot_required' => true,
                    'reboot_required_packages' => $this->stringList($facts['reboot_required_packages'] ?? []),
                ],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function issue(
        string $code,
        DriftKind $kind,
        string $summary,
        bool $restorable,
        array $detail = [],
    ): UpdatePostureIssue {
        return new UpdatePostureIssue(
            code: $code,
            kind: $kind,
            summary: $summary,
            restorable: $restorable,
            detail: [
                'driver' => $this->key(),
                ...$detail,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function unverifiableIssue(array $detail = []): UpdatePostureIssue
    {
        return $this->issue(
            code: 'node.updates_unverifiable',
            kind: DriftKind::Unverifiable,
            summary: 'This node update posture could not be verified through unattended-upgrades.',
            restorable: true,
            detail: $detail,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(string $output): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
