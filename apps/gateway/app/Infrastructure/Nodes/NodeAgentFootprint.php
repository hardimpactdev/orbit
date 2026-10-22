<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string BinaryPath = '/usr/local/bin/orbit-agent';

    public const string ConfigurationPath = '/etc/orbit/agent/config.toml';

    public const string CertificatePath = '/etc/orbit/agent/ca.pem';

    /** The agent's secret, `root:root` mode `0600`; the Gateway keeps only its SHA-256 hash (ADR 0155). */
    public const string SecretPath = '/etc/orbit/agent/secret';

    public const string UnitPath = '/etc/systemd/system/orbit-agent.service';

    public const string Service = 'orbit-agent';

    public const string Marker = '# Managed by Orbit: agent';

    public const string CandidateSuffix = '.orbit-candidate';

    /**
     * The Node-local lock that `orbit self-update` holds while it replaces and restarts the agent. The converge
     * moves its binary into place under the same lock, so the two never swap the agent at the same time.
     */
    public const string UpdateLockPath = '/run/lock/orbit-self-update.lock';
}
