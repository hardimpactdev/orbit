<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\ToolManagerRecord;
use Throwable;

/**
 * Where each active Tool Manager on a Linux Node keeps its state, the files the Node agent reads for its `packages`
 * facts. The agent reads the first candidate that exists; the agent's unit binds the candidates under `/home`.
 */
final readonly class NodeToolScopes
{
    public function __construct(private ManagedUserAccountResolver $accounts) {}

    /** @return list<array{manager: string, paths: list<string>}> */
    public function forNode(Node $node): array
    {
        try {
            $home = $this->accounts->resolve($node)->home;
        } catch (Throwable) {
            $home = null;
        }
        $scopes = [];
        $names = ToolManagerRecord::query()->where('node_id', $node->id)->where('status', LifecycleStatus::Active)->orderBy('name')->pluck('name');
        foreach ($names as $name) {
            $paths = match ($name) {
                'apt' => ['/var/lib/dpkg/status'],
                'composer' => ['/opt/orbit/composer'],
                'vp' => ['/opt/orbit/vite-plus', ...($home === null ? [] : [$home.'/.vite-plus', $home.'/.local/share/vite-plus'])],
                'brew' => ['/home/linuxbrew/.linuxbrew'],
                default => [],
            };
            if ($paths !== []) {
                $scopes[] = ['manager' => (string) $name, 'paths' => $paths];
            }
        }

        return $scopes;
    }

    /**
     * The candidates under `/home` or `/root`, which the agent's unit hides unless it binds them read-only.
     *
     * @return list<string>
     */
    public function homePaths(Node $node): array
    {
        $paths = [];
        foreach ($this->forNode($node) as $scope) {
            foreach ($scope['paths'] as $path) {
                if (str_starts_with($path, '/home/') || str_starts_with($path, '/root/')) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
