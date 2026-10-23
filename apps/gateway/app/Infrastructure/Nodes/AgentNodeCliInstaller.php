<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;

/**
 * Installs the Orbit CLI through the agent's `cli.install`, in the layout `orbit self-update` keeps (see
 * docs/reference/node-provisioning.md#orbit-cli). The agent holds the Node's update lock while it installs, reads
 * the link path again under it, and runs every found binary as the managed user. The Gateway picks the release
 * asset and root's CLI profile, and maps the agent's refusals to its CLI error codes.
 */
final readonly class AgentNodeCliInstaller implements NodeCliInstaller
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation
    {
        if ($node->platform !== 'linux') {
            throw new ResourceOperationException('cli.platform_unsupported', 'The Gateway installs the Orbit CLI on Linux Nodes only.', 422);
        }

        $profile = NodeCliFootprint::configuration($this->gatewayAddress());
        $asset = null;
        $unavailable = null;
        try {
            $asset = $this->asset($node, $release);
        } catch (ResourceOperationException $exception) {
            $unavailable = $exception;
        }

        try {
            $result = $this->agents->run($node, 'cli.install', [
                'user' => $node->user,
                'release' => $asset,
                'profile' => $profile,
            ]);
        } catch (AgentCommandRefused $refusal) {
            throw match ($refusal->errorCode) {
                'cli.release_needed' => $unavailable ?? new ResourceOperationException('cli.release_unavailable', 'The desired CLI release is not published yet.', 409, $refusal),
                'cli.foreign_binary' => self::foreign($refusal),
                'cli.update_busy' => new ResourceOperationException('node.update_busy', "A self-update on node [{$node->name}] held its update lock for more than 300 seconds.", 409, $refusal),
                'cli.download_failed' => new ResourceOperationException('cli.download_failed', 'The Orbit CLI could not be downloaded on the Node.', 502, $refusal),
                'cli.checksum_mismatch' => new ResourceOperationException('cli.checksum_mismatch', 'The downloaded Orbit CLI failed checksum verification.', 502, $refusal),
                'cli.candidate_invalid' => new ResourceOperationException('cli.candidate_invalid', "The downloaded Orbit CLI does not run or does not report version {$release->version}.", 502, $refusal),
                default => new ResourceOperationException('cli.install_failed', 'The Orbit CLI could not be installed or configured on the Node.', 502, $refusal),
            };
        }

        $version = $result['installed'] ?? null;

        return new NodeCliInstallation(
            outcome: is_string($version) ? NodeCliInstallation::Installed : NodeCliInstallation::Present,
            configured: ($result['configured'] ?? false) === true,
            version: is_string($version) ? $version : null,
        );
    }

    public function inspect(Node $node): string
    {
        try {
            return StoredValue::string($this->agents->run($node, 'cli.inspect', ['user' => $node->user])['state'] ?? null);
        } catch (AgentCommandRefused $refusal) {
            throw new ResourceOperationException('cli.inspection_failed', 'The Orbit CLI on the Node could not be inspected.', 502, $refusal);
        }
    }

    private static function foreign(AgentCommandRefused $refusal): ResourceOperationException
    {
        return new ResourceOperationException(
            'cli.foreign_binary',
            NodeCliFootprint::LinkPath.' on the Node is a link, a script, or a file Orbit did not install. Move it aside, then converge again.',
            409,
            $refusal,
        );
    }

    /** @return array{version: string, url: string, sha256: string} */
    private function asset(Node $node, DesiredCliReleaseData $release): array
    {
        if (! $release->isAvailable() || $release->version === null) {
            throw new ResourceOperationException('cli.release_unavailable', 'The desired CLI release is not published yet.', 409);
        }

        $platform = NodeCliFootprint::platform(is_string($node->architecture) ? $node->architecture : '');

        foreach ($release->assets as $asset) {
            if ($asset->platform === $platform) {
                return ['version' => $release->version, 'url' => $asset->url, 'sha256' => $asset->sha256];
            }
        }

        throw new ResourceOperationException('cli.architecture_unsupported', "The CLI release has no binary for {$platform}.", 422);
    }

    private function gatewayAddress(): string
    {
        $address = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
            ->value('wireguard_ip');

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('cli.configuration_failed', 'The active Gateway has no managed WireGuard address.', 409);
        }

        return $address;
    }
}
