<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;

final readonly class CreateAppSourceOnNode
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{path: string, result: RemoteShellResult}
     */
    public function handle(Node $node, string $name, ?string $repository, ?string $domain = null): array
    {
        $path = $this->appPath($node, $name, $domain);
        $script = $repository === null
            ? sprintf('mkdir -p %s', escapeshellarg($path))
            : sprintf('git clone %s %s', escapeshellarg($repository), escapeshellarg($path));

        return [
            'path' => $path,
            'result' => $this->remoteShell->run($node, $script),
        ];
    }

    private function appPath(Node $node, string $name, ?string $domain): string
    {
        if (is_string($domain) && $domain !== '') {
            return "/home/{$name}/app";
        }

        $user = $node->user ?: ($node->ssh_user ?: 'orbit');
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return "{$home}/apps/{$name}";
    }
}
