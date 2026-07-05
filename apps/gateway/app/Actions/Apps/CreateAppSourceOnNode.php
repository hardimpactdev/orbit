<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Apps\RemoteAppSourceCreator;

final readonly class CreateAppSourceOnNode
{
    public function __construct(
        private RemoteAppSourceCreator $sourceCreator,
    ) {}

    /**
     * @return array{path: string, result: RemoteShellResult}
     */
    public function handle(Node $node, string $name, ?string $repository, ?string $domain = null): array
    {
        $path = $this->appPath($node, $name, $domain);
        $user = $node->user ?: 'orbit';

        return [
            'path' => $path,
            'result' => $this->sourceCreator->create($node, $user, $path, $repository),
        ];
    }

    private function appPath(Node $node, string $name, ?string $domain): string
    {
        if (is_string($domain) && $domain !== '') {
            return "/home/{$name}/app";
        }

        $user = $node->user ?: 'orbit';
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return "{$home}/apps/{$name}";
    }
}
