<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\RemoteShell\GatewayHostExecution;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Security\HomeDirectoryLockdownInstaller;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SysctlBaselineInstaller;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:kan-defect
 */
final readonly class NodeSecurityPostureProbe
{
    public function __construct(
        private ?RunsInternalCommands $localExecutor = null,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function diff(Node $node): array
    {
        if (! $this->appliesTo($node)) {
            return [];
        }

        $drift = [
            ...$this->recordDrift($node),
            ...$this->firewallDrift($node),
        ];

        if (
            is_string($node->wireguard_address)
            && $node->wireguard_address !== ''
            && $this->managedUser($node) !== ''
        ) {
            $drift = [
                ...$drift,
                ...$this->remoteDrift($node),
            ];
        }

        return $drift;
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        return [];
    }

    public function restore(Node $node, DriftEntry $entry): void
    {
        match ($entry->key) {
            'node.security.sshd_config', 'node.security.sshd_listen' => app(SshdHardenedInstaller::class)->installFor(
                $node,
            ),
            'node.security.public_ssh_deny' => app(PublicSshDenyInstaller::class)->installFor($node),
            'node.security.sysctl' => app(SysctlBaselineInstaller::class)->installFor($node),
            'node.security.runtime_user' => throw new RuntimeException(
                'Runtime user drift is report-only; re-bake or migrate the node.',
            ),
            'node.security.home_perms' => $this->restoreHomePermissions($node),
            default => throw new RuntimeException("Node security cannot restore drift key '{$entry->key}'."),
        };
    }

    private function restoreHomePermissions(Node $node): void
    {
        $report = app(HomeDirectoryLockdownInstaller::class)->restoreFor($node);

        if ($report->successful) {
            return;
        }

        throw new RuntimeException(
            $report->summary !== ''
                ? $report->summary
                : "Failed to restore home permissions for node {$node->name}.",
        );
    }

    private function appliesTo(Node $node): bool
    {
        return $node->isActive() && str_starts_with((string) $node->platform, 'ubuntu');
    }

    /**
     * @return list<DriftEntry>
     */
    private function recordDrift(Node $node): array
    {
        $drift = [];

        if ($this->managedUser($node) === '') {
            $drift[] = new DriftEntry(
                family: 'node',
                key: 'node.security.runtime_user',
                kind: DriftKind::Divergent,
                summary: "Node {$node->name} is missing its Orbit runtime user.",
                detail: [
                    'expected' => 'non-empty Orbit runtime user',
                    'actual' => $node->user,
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function firewallDrift(Node $node): array
    {
        $rules = FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('owner', 'node-security')
            ->where('protected', true)
            ->where('action', 'deny')
            ->where('direction', 'incoming')
            ->where('protocol', 'tcp')
            ->where('port', '22')
            ->where('interface', 'public')
            ->pluck('address_family')
            ->all();

        if (in_array('v4', $rules, true) && in_array('v6', $rules, true)) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.security.public_ssh_deny',
                kind: DriftKind::Missing,
                summary: "Node {$node->name} is missing protected public SSH deny rules.",
                detail: [
                    'expected_families' => ['v4', 'v6'],
                    'actual_families' => $rules,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function remoteDrift(Node $node): array
    {
        try {
            $result = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:node-security-posture:probe',
                arguments: [$this->managedUser($node)],
                transportOptions: [
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'node-security-posture.probe',
                    ],
                    // Host-owned posture (sshd/sysctl/home) must not run as
                    // gateway_local inside the orbit-gateway container.
                    'force_remote_host' => GatewayHostExecution::shouldForceRemoteHostFor($node),
                    'timeout' => 30,
                    'throw' => false,
                ],
            );
        } catch (Throwable $exception) {
            return [
                $this->postureProbeFailed(
                    $node,
                    reason: 'exception',
                    error: $exception->getMessage(),
                ),
            ];
        }

        if (! $result->successful()) {
            return [
                $this->postureProbeFailed(
                    $node,
                    reason: 'non_success',
                    error: $this->summarizeDiagnostic($result->stderr !== '' ? $result->stderr : $result->stdout),
                    exitCode: $result->exitCode,
                ),
            ];
        }

        $posture = $this->successData($result->stdout);

        if ($posture === []) {
            return [
                $this->postureProbeFailed(
                    $node,
                    reason: 'malformed_payload',
                    error: 'empty or malformed posture probe payload',
                    exitCode: $result->exitCode,
                ),
            ];
        }

        $checks = [
            'runtime_user' => 'node.security.runtime_user',
            'sshd_config' => 'node.security.sshd_config',
            'sshd_listen' => 'node.security.sshd_listen',
            'sysctl' => 'node.security.sysctl',
            'home_perms' => 'node.security.home_perms',
        ];

        $drift = [];

        foreach ($checks as $check => $key) {
            if (($posture[$check] ?? null) === true) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: 'node',
                key: $key,
                kind: $key === 'node.security.home_perms' ? DriftKind::Divergent : DriftKind::Missing,
                summary: "Node {$node->name} failed security check {$check}.",
                detail: [
                    'check' => $check,
                    'observed' => $posture[$check] ?? null,
                ],
            );
        }

        return $drift;
    }

    private function postureProbeFailed(
        Node $node,
        string $reason,
        string $error,
        ?int $exitCode = null,
    ): DriftEntry {
        $detail = [
            'reason' => $reason,
            'error' => $this->summarizeDiagnostic($error),
        ];

        if ($exitCode !== null) {
            $detail['exit_code'] = $exitCode;
        }

        return new DriftEntry(
            family: 'node',
            key: 'node.security.posture_probe_failed',
            kind: DriftKind::Unverifiable,
            summary: "Node {$node->name} security posture could not be inspected.",
            detail: $detail,
        );
    }

    private function summarizeDiagnostic(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($normalized === '') {
            return 'probe failed';
        }

        if (strlen($normalized) > 240) {
            return substr($normalized, 0, 237).'...';
        }

        return $normalized;
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
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

        // Failure envelopes must never be read as empty healthy success data.
        if (array_key_exists('error', $payload)) {
            return [];
        }

        if (! array_key_exists('success', $payload) || ! is_array($payload['success'])) {
            return [];
        }

        $success = $payload['success'];

        if (! array_key_exists('data', $success) || ! is_array($success['data'])) {
            return [];
        }

        $data = $success['data'];

        if ($data === []) {
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

    private function managedUser(Node $node): string
    {
        return trim((string) $node->user);
    }
}
