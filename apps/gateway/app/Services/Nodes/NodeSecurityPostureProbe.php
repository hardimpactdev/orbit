<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Security\SysctlBaselineInstaller;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:kan-defect
 */
final readonly class NodeSecurityPostureProbe
{
    // @orbit-ssh-lane transitional-ssh
    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?RemoteLocalExecutor $localExecutor = null,
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

    public function snapshotForAdopt(Node $node, bool $includeHostKey = false): ProbeSnapshot
    {
        if (! $includeHostKey || ! $this->hostKeyMissing($node)) {
            return new ProbeSnapshot([]);
        }

        return new ProbeSnapshot([
            $this->hostKeyKey($node) => [
                'host' => $node->host,
                'pin_mode' => 'tofu',
            ],
        ]);
    }

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        $key = $this->hostKeyKey($node);
        $hostKey = $snapshot->get($key);

        if (! is_array($hostKey) || ! $this->hostKeyMissing($node)) {
            return [];
        }

        $pinned = app(SshHostKeyPinner::class)->pin($node->host);
        app(SshHostKeyPinner::class)->persist($node, $pinned);

        return [
            new AdoptResult(
                family: 'node',
                key: $key,
                action: AdoptAction::Updated,
                summary: "Pinned SSH host key for {$node->name}.",
                detail: [
                    'fingerprint' => $pinned->fingerprint,
                    'pin_mode' => $pinned->pinMode,
                ],
            ),
        ];
    }

    public function restore(Node $node, DriftEntry $entry): void
    {
        if ($entry->key === $this->hostKeyKey($node)) {
            if (! $this->hostKeyMissing($node)) {
                throw new RuntimeException('Host key is already pinned; refusing to overwrite it through restore.');
            }

            $pinned = app(SshHostKeyPinner::class)->pin($node->host);
            app(SshHostKeyPinner::class)->persist($node, $pinned);

            return;
        }

        $shell = $this->remoteShell ?? app(RemoteShell::class);

        match ($entry->key) {
            'node.security.sshd_config', 'node.security.sshd_listen' => app(SshdHardenedInstaller::class)->installFor(
                $node,
                $shell,
            ),
            'node.security.public_ssh_deny' => app(PublicSshDenyInstaller::class)->installFor($node, $shell),
            'node.security.sysctl' => app(SysctlBaselineInstaller::class)->installFor($node, $shell),
            'node.security.runtime_user' => throw new RuntimeException(
                'Runtime user drift is report-only; re-bake or migrate the node.',
            ),
            'node.security.home_perms' => throw new RuntimeException(
                'Home permission drift is report-only; re-bake the node.',
            ),
            default => throw new RuntimeException("Node security cannot restore drift key '{$entry->key}'."),
        };
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

        if ($this->hostKeyMissing($node)) {
            $drift[] = new DriftEntry(
                family: 'node',
                key: $this->hostKeyKey($node),
                kind: DriftKind::Missing,
                summary: "Node {$node->name} is missing pinned SSH host-key material.",
                detail: [
                    'host' => $node->host,
                    'adoptable' => true,
                ],
            );
        }

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
                    'timeout' => 30,
                    'throw' => false,
                ],
            );
        } catch (Throwable) {
            return [];
        }

        if (! $result->successful()) {
            return [];
        }

        $posture = $this->successData($result->stdout);

        if ($posture === []) {
            return [];
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

        if (! array_key_exists('success', $payload) || ! is_array($payload['success'])) {
            return [];
        }

        $success = $payload['success'];

        if (! array_key_exists('data', $success) || ! is_array($success['data'])) {
            return [];
        }

        $data = $success['data'];

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

    private function hostKeyMissing(Node $node): bool
    {
        return (
            ! is_string($node->host_key_type)
            || $node->host_key_type === ''
            || ! is_string($node->host_key_public)
            || $node->host_key_public === ''
            || ! is_string($node->host_key_fingerprint)
            || $node->host_key_fingerprint === ''
        );
    }

    private function hostKeyKey(Node $node): string
    {
        return "node.security.host_key.{$node->name}";
    }
}
