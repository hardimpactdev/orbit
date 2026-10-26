<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

/** Typed adoption intent. The agent owns all storage and checkout exchanges. */
final readonly class GatewayReleaseAdoptionFiles
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function checkout(GatewayReleaseLayout $layout): string
    {
        $result = $this->run($layout, 'gateway.check-release-adoption', [], 'adopt');
        if (! is_string($result['sha'] ?? null) || ! GatewayReleaseCommit::isSha($result['sha'])) {
            throw $this->malformed('adopt');
        }

        return $result['sha'];
    }

    public function repository(GatewayReleaseLayout $layout, string $sha): string
    {
        $result = $this->run($layout, 'gateway.adopt-release-repository', ['sha' => $sha], 'adopt');
        if (! in_array($result['repository'] ?? null, ['created', 'existing'], true)) {
            throw $this->malformed('adopt');
        }

        return $result['repository'];
    }

    public function phaseOne(GatewayReleaseLayout $layout, string $id): bool
    {
        $result = $this->run($layout, 'gateway.inspect-adoption-origin', [], 'adopt');
        if (! array_key_exists('sha', $result) || ($result['sha'] !== null && (! is_string($result['sha']) || ! GatewayReleaseCommit::isSha($result['sha'])))) {
            throw $this->malformed('adopt');
        }

        return $result['sha'] !== null && GatewayReleaseCommit::id($result['sha']) === $id;
    }

    public function target(GatewayReleaseLayout $layout, string $target, ?string $source): string
    {
        $result = $this->run($layout, 'gateway.resolve-adoption-target', ['target' => $target, 'source' => $source], 'adopt');
        if (! is_string($result['sha'] ?? null) || ($result['sha'] !== $target && (! GatewayReleaseCommit::isSha($result['sha']) || ! str_starts_with($result['sha'], $target)))) {
            throw $this->malformed('adopt');
        }

        return $result['sha'];
    }

    /** @return array{env: string, env_link: string, app_version_removed: bool, env_backups: list<string>} */
    public function environment(GatewayReleaseLayout $layout): array
    {
        $result = $this->run($layout, 'gateway.adopt-release-environment', [], 'adopt');
        if (! in_array($result['env'] ?? null, ['created', 'existing'], true)
            || ! in_array($result['env_link'] ?? null, ['linked', 'existing'], true)
            || ! is_bool($result['app_version_removed'] ?? null)
            || ! is_array($result['env_backups'] ?? null)
            || ! array_is_list($result['env_backups'])
            || array_any($result['env_backups'], static fn (mixed $path): bool => ! is_string($path))) {
            throw $this->malformed('adopt');
        }

        return ['env' => $result['env'], 'env_link' => $result['env_link'], 'app_version_removed' => $result['app_version_removed'], 'env_backups' => $result['env_backups']];
    }

    public function restoreEnvironment(GatewayReleaseLayout $layout): string
    {
        $result = $this->run($layout, 'gateway.restore-adoption-environment', [], 'adopt');
        if (! in_array($result['environment'] ?? null, ['unchanged', 'restored', 'link_kept'], true)) {
            throw $this->malformed('adopt');
        }

        return $result['environment'];
    }

    /** @return array{storage: string, storage_method?: string, storage_gap_us?: int} */
    public function storage(GatewayReleaseLayout $layout): array
    {
        $result = $this->run($layout, 'gateway.adopt-release-storage', [], 'adopt');
        if (($result['storage'] ?? null) === 'existing') {
            return ['storage' => 'existing'];
        }
        if (($result['storage'] ?? null) === 'moved' && ($result['storage_method'] ?? null) === 'exchange' && ($result['storage_gap_us'] ?? null) === 0) {
            return ['storage' => 'moved', 'storage_method' => 'exchange', 'storage_gap_us' => 0];
        }
        throw $this->malformed('adopt');
    }

    /** @return array{method: string, gap_us: int, from: string, to: string} */
    public function switch(GatewayReleaseLayout $layout, string $id, string $stamp): array
    {
        $result = $this->run($layout, 'gateway.switch-release-adoption', ['release' => $id, 'stamp' => $stamp], 'switch');
        if (($result['method'] ?? null) !== 'exchange' || ($result['gap_us'] ?? null) !== 0 || ! is_string($result['from'] ?? null) || ! is_string($result['to'] ?? null)) {
            throw $this->malformed('switch');
        }

        return ['method' => 'exchange', 'gap_us' => 0, 'from' => $result['from'], 'to' => $result['to']];
    }

    public function restore(GatewayReleaseLayout $layout, string $id, string $stamp): void
    {
        $result = $this->run($layout, 'gateway.restore-release-adoption', ['release' => $id, 'stamp' => $stamp], 'switch_back');
        if (! is_bool($result['changed'] ?? null)) {
            throw $this->malformed('switch_back');
        }
    }

    public function finish(GatewayReleaseLayout $layout, string $stamp): ?string
    {
        $result = $this->run($layout, 'gateway.finish-release-adoption', ['stamp' => $stamp], 'adopt');
        if (! array_key_exists('path', $result) || ($result['path'] !== null && ! is_string($result['path']))) {
            throw $this->malformed('adopt');
        }

        return $result['path'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function run(GatewayReleaseLayout $layout, string $command, array $input, string $step): array
    {
        $node = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->first();
        if (! $node instanceof Node) {
            throw new GatewayReleaseException($step, 'gateway.release_gateway_node_missing', 'No active Gateway Node exists for release adoption.', 500);
        }
        try {
            return $this->agents->run($node, $command, ['checkout' => $layout->applicationPath, ...$input]);
        } catch (AgentCommandRefused $refused) {
            $code = $refused->errorCode === 'gateway.release_adoption_failed' ? match ($command) {
                'gateway.switch-release-adoption', 'gateway.finish-release-adoption' => 'gateway.release_switch_failed',
                'gateway.restore-release-adoption' => 'gateway.release_switch_back_failed',
                default => 'gateway.release_adopt_storage_failed',
            } : $refused->errorCode;
            $message = $refused->getMessage();
            if ($code === 'gateway.release_adopt_local_changes' && is_string($refused->details['path'] ?? null)) {
                $message .= ' Changed path: '.$refused->details['path'];
            }
            throw new GatewayReleaseException($step, $code, $message, str_ends_with($code, '_failed') ? 500 : 409, previous: $refused);
        } catch (AgentRunnerFailure $failure) {
            throw new GatewayReleaseException($step, 'gateway.release_adoption_failed', 'The agent returned no release adoption result.', 500, previous: $failure);
        }
    }

    private function malformed(string $step): GatewayReleaseException
    {
        return new GatewayReleaseException($step, 'gateway.release_adoption_failed', 'The agent returned an invalid release adoption result.', 500);
    }
}
