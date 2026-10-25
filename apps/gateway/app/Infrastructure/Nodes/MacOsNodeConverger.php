<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\MacOsEnrollmentObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/**
 * Enrolls a Mac through the pinned command binary as the existing account.
 *
 * The checks are host key, account, platform and architecture, then the
 * WireGuard address. Nothing in this path creates a user, installs packages,
 * or rewrites DNS, firewall, or tunnel state.
 */
final readonly class MacOsNodeConverger
{
    public function __construct(
        private HostKeyScanner $hostKeys,
        private AdmissionAgentRunner $agents,
        private SshKeyProvider $sshKeys,
    ) {}

    public function enroll(
        Node $node,
        string $host,
        int $port,
        string $account,
        string $address,
        ?string $storedFingerprint,
        ?string $expectedFingerprint,
    ): MacOsEnrollmentObservation {
        $name = $node->name;
        try {
            $hostKey = $this->hostKeys->scan($host, $port);
        } catch (SshHostKeyScanException $exception) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$name}].",
                previous: $exception,
                result: $exception->result,
            );
        }

        if (! $this->fingerprintMatches($storedFingerprint, $hostKey->fingerprint)) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_changed',
                message: "The SSH host key changed for node [{$name}].",
            );
        }

        if (
            $storedFingerprint === null
            && $expectedFingerprint === null
        ) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_fingerprint_required',
                message: "An expected SSH host fingerprint is required for node [{$name}].",
            );
        }

        if (! $this->fingerprintMatches($expectedFingerprint, $hostKey->fingerprint)) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_mismatch',
                message: "The SSH host fingerprint did not match for node [{$name}].",
            );
        }

        $knownHosts = $this->temporaryKnownHosts($host, $port, $hostKey);

        try {
            $connection = new SshConnection(
                host: $host,
                user: $account,
                port: $port,
                identityFile: $this->sshKeys->privateKeyPath(),
                knownHostsFile: $knownHosts,
                commandTimeout: 30.0,
                shareConnection: false,
            );
            $original = $node->only(['platform', 'architecture', 'user']);
            $node->forceFill(['platform' => 'macos', 'user' => $account]);
            $step = 'machine-architecture';
            try {
                $architecture = $this->agents->architectureOver($node, $connection);
                $node->architecture = $architecture;
                $step = 'agent';
                $this->agents->installOver($node, $connection);
                $step = 'identity';
                $observed = $this->agents->runOver($node, $connection, 'nodes.inspect-macos-enrollment', ['address' => $address]);

                if (! is_string($observed['architecture'] ?? null) || ! MachineArchitecture::isValid($observed['architecture'])) {
                    throw new AgentRunnerFailure('nodes.inspect-macos-enrollment', AgentRunnerFailure::MalformedResult, 'The agent returned no valid Mac architecture.');
                }

                return new MacOsEnrollmentObservation($observed['architecture'], $hostKey);
            } catch (AgentCommandRefused $error) {
                [$step, $code] = match ($error->errorCode) {
                    'agent.platform_unsupported', 'nodes.macos_platform_mismatch' => ['machine-architecture', 'node.platform_mismatch'],
                    'nodes.macos_architecture_unavailable' => ['machine-architecture', 'node.architecture_unavailable'],
                    'nodes.macos_wireguard_required' => ['identity', 'node.wireguard_required'],
                    default => [$step, $error->errorCode],
                };
                throw new NodeProvisioningException(step: $step, errorCode: $code, message: $error->getMessage(), previous: $error);
            } catch (AgentRunnerFailure $error) {
                $accountFailure = $step === 'machine-architecture' && $error->result?->exitCode === 255;
                throw new NodeProvisioningException(
                    step: $accountFailure ? 'account' : $step,
                    errorCode: $accountFailure ? 'node.account_unavailable' : ($step === 'machine-architecture' ? 'node.architecture_unavailable' : 'agent.runner_failed'),
                    message: $error->getMessage(), previous: $error, result: $error->result,
                );
            } finally {
                $node->forceFill($original);
            }
        } finally {
            if (is_file($knownHosts)) {
                unlink($knownHosts);
            }
        }
    }

    private function fingerprintMatches(?string $expected, string $actual): bool
    {
        if ($expected === null) {
            return true;
        }

        return strlen($expected) === strlen($actual) && hash_equals($expected, $actual);
    }

    private function temporaryKnownHosts(string $host, int $port, HostKey $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orbit-macos-known-hosts-');

        if ($path === false) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$host}].",
            );
        }

        $label = $port === 22 ? $host : "[{$host}]:{$port}";
        $contents = "{$label} {$key->type} {$key->value}\n";
        $written = file_put_contents($path, $contents, LOCK_EX);

        if ($written !== strlen($contents) || ! chmod($path, 0600)) {
            unlink($path);

            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$host}].",
            );
        }

        return $path;
    }
}
