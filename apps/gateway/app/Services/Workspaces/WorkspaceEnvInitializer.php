<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\EnvFileEditor;
use App\Services\RemoteShell\RemoteEnvFile;
use RuntimeException;

final readonly class WorkspaceEnvInitializer
{
    public function __construct(
        private WorkspacePlacement $placement,
        private WorkspaceEnvRenderer $renderer,
        private EnvFileEditor $editor,
    ) {}

    public function initialize(Workspace $workspace): bool
    {
        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no Orbit-managed owner.");
        }

        if ($node->hasActiveRole('gateway') && ! is_dir($workspace->path)) {
            return false;
        }

        $envPath = rtrim(string: $workspace->path, characters: '/').'/.env';
        $existing = $this->read($node, $envPath);
        $created = $existing === null;
        $contents =
            $existing ?? $this->read($node, rtrim(string: $workspace->path, characters: '/').'/.env.example') ?? '';
        $updated = $this->editor->update($contents, $this->renderer->applicableValues($workspace));

        if ($created || $updated !== $contents) {
            $this->write($node, $envPath, $updated);
        }

        return $created;
    }

    private function read(Node $node, string $path): ?string
    {
        if ($node->hasActiveRole('gateway')) {
            return is_file($path) ? (string) file_get_contents($path) : null;
        }

        return app(RemoteEnvFile::class)->read($node, $path);
    }

    private function write(Node $node, string $path, string $contents): void
    {
        if ($node->hasActiveRole('gateway')) {
            if (! is_dir(dirname($path))) {
                mkdir(directory: dirname($path), permissions: 0o775, recursive: true);
            }

            file_put_contents($path, $contents);

            return;
        }

        app(RemoteEnvFile::class)->write($node, $path, $contents);
    }
}
