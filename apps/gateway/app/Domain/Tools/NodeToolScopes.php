<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\Node;

/**
 * Where each Tool Manager a Linux Node can run keeps its state, the files the Node agent reads for its `packages`
 * facts. Every manager is listed, recorded or not, so `tool:adopt` and `tool:scan` can see a package Orbit has never
 * managed. The agent reads the first candidate that exists and reports nothing while none does; the agent's unit binds
 * the candidates under `/home`.
 */
final readonly class NodeToolScopes
{
    /** @return list<array{manager: string, paths: list<string>}> */
    public function forNode(Node $node): array
    {
        // The account Orbit creates has this home, as the converge assumes for its apps root; reading it from the Node
        // would cost an SSH call on every watch-list request.
        $home = $node->user === 'root' ? '/root' : '/home/'.$node->user;
        // A home that is not a plain path, such as one with a space, can't name a unit bind; its candidates go.
        if (preg_match('#\A(/[A-Za-z0-9._-]+)+\z#D', $home) !== 1) {
            $home = null;
        }
        if ($node->platform !== 'linux') {
            return [];
        }

        return [
            ['manager' => ToolManagerName::Apt->value, 'paths' => ['/var/lib/dpkg/status']],
            ['manager' => ToolManagerName::Brew->value, 'paths' => ['/home/linuxbrew/.linuxbrew']],
            ['manager' => ToolManagerName::Composer->value, 'paths' => ['/opt/orbit/composer']],
            ['manager' => ToolManagerName::Vp->value, 'paths' => ['/opt/orbit/vite-plus', ...($home === null ? [] : [$home.'/.vite-plus', $home.'/.local/share/vite-plus'])]],
        ];
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
