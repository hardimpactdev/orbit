<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Throwable;

/**
 * The hibernation markers and access logs of a Node, through the agent's `hibernation.marker` and
 * `hibernation.times` (docs/reference/app-dev-runtime-hibernation.md).
 */
final readonly class RemoteHibernationMarkerStore implements HibernationMarkerStore
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function markAwake(Node $node, string $key): void
    {
        $this->mark($node, 'mark-awake', RuntimeHibernation::awakePath($key));
    }

    public function markAsleep(Node $node, string $key): void
    {
        $this->run($node, 'mark-asleep', ['operation' => 'remove', 'path' => RuntimeHibernation::awakePath($key)]);
    }

    public function markCold(Node $node, string $key): void
    {
        $this->mark($node, 'mark-cold', RuntimeHibernation::coldPath($key));
    }

    public function clearCold(Node $node, string $key): void
    {
        $this->run($node, 'clear-cold', ['operation' => 'remove', 'path' => RuntimeHibernation::coldPath($key)]);
    }

    public function lastActivityUnix(Node $node, string $key): ?int
    {
        $times = array_values(array_filter(
            $this->times($node, [RuntimeHibernation::accessLogPath($key), RuntimeHibernation::awakePath($key)]),
            static fn (?int $time): bool => $time !== null,
        ));

        return $times === [] ? null : max($times);
    }

    public function isAwake(Node $node, string $key): bool
    {
        return $this->times($node, [RuntimeHibernation::awakePath($key)])[0] !== null;
    }

    public function isCold(Node $node, string $key): bool
    {
        return $this->times($node, [RuntimeHibernation::coldPath($key)])[0] !== null;
    }

    private function mark(Node $node, string $step, string $path): void
    {
        $this->run($node, $step, [
            'operation' => 'mark',
            'marker_directory' => RuntimeHibernation::MarkerDirectory,
            'log_directory' => RuntimeHibernation::AccessLogDirectory,
            'path' => $path,
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int|null> Each path's modification time, or null when it is missing or the Node does not answer.
     */
    private function times(Node $node, array $paths): array
    {
        $this->requireAddress($node);

        try {
            $times = $this->agents->run($node, 'hibernation.times', ['paths' => $paths])['times'] ?? [];
        } catch (Throwable) {
            $times = [];
        }

        return array_map(static fn (string $path): ?int => is_array($times) && is_int($times[$path] ?? null) ? $times[$path] : null, $paths);
    }

    /** @param  array<string, mixed>  $input */
    private function run(Node $node, string $step, array $input): void
    {
        $this->requireAddress($node);

        try {
            $this->agents->run($node, 'hibernation.marker', $input);
        } catch (Throwable) {
            throw new HibernationException(
                errorCode: 'hibernation.marker_failed',
                message: "Hibernation marker step [{$step}] failed on Node [{$node->name}].",
            );
        }
    }

    private function requireAddress(Node $node): void
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new HibernationException(
                errorCode: 'hibernation.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }
    }
}
