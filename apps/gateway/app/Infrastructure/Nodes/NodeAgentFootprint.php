<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.4.4';

    /** @var list<string> */
    public const array Architectures = ['x86_64', 'aarch64'];

    /**
     * SHA-256 checksums from the pinned release's `SHA256SUMS` asset.
     *
     * @var array{x86_64: string, aarch64: string}
     */
    private const array ReleaseManifest = [
        'x86_64' => 'b2abfa992720645087929f3fed83fbfa8e92fbdcb510d5b755a030b01f305fdf',
        'aarch64' => 'b5a3ddbd25a4b8f546967f56e7d057fc110e880f526cf7fa97ca5409450c7632',
    ];

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

    public static function checksum(string $architecture): string
    {
        return match ($architecture) {
            'x86_64', 'aarch64' => self::ReleaseManifest[$architecture],
            default => throw new \InvalidArgumentException('Unsupported Node agent architecture.'),
        };
    }

    public static function assetName(string $architecture): string
    {
        return 'orbit-agent-'.self::Version.'-linux-'.$architecture;
    }

    public static function downloadUrl(string $architecture): string
    {
        return 'https://github.com/hardimpactdev/orbit/releases/download/agent-v'.self::Version
            .'/'.self::assetName($architecture);
    }
}
