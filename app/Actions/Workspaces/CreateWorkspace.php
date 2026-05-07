<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use RuntimeException;

final readonly class CreateWorkspace
{
    public const array SUPPORTED_PHP_VERSIONS = ['8.5'];

    public function __construct(
        private RemoteShell $remoteShell,
        private SetupWorkspace $setupWorkspace,
    ) {}

    /**
     * @return array{
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function handle(App $app, string $name, string $base = 'main', ?string $phpVersion = null): array
    {
        $app->loadMissing('node');

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new WorkspaceCreateFailed(
                'workspace.parent_app_invalid',
                "App '{$app->name}' does not have an owning app node.",
                ['field' => 'app', 'app' => $app->name],
            );
        }

        if ($phpVersion !== null && ! in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)) {
            throw new WorkspaceCreateFailed(
                'validation_failed',
                'Unsupported PHP version.',
                ['field' => 'php_version', 'reason' => 'unsupported_php_version'],
            );
        }

        $preflight = $this->remoteShell->run($node, 'true', ['timeout' => 30]);

        if (! $preflight->successful()) {
            throw new WorkspaceCreateFailed(
                'workspace.ssh_failure',
                "Gateway could not reach app node '{$node->name}' before creating workspace intent.",
                [
                    'node' => $node->name,
                    'reason' => trim($preflight->output()) ?: 'ssh preflight failed',
                ],
            );
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'name' => $name,
            'path' => $this->workspacePath($app, $name),
            'php_version' => $phpVersion,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $workspace->setRelation('app', $app);

        $warnings = [];
        $httpProbe = [
            'reachable' => false,
            'status' => 'not_run',
        ];

        $worktree = $this->remoteShell->run($node, $this->worktreeScript($app, $workspace, $base), ['timeout' => 300]);

        if (! $worktree->successful()) {
            $warnings[] = [
                'code' => 'workspace.path_missing',
                'family' => 'workspace',
                'message' => "Workspace path '{$workspace->path}' is missing on node '{$node->name}'. SSH enactment failed after gateway intent was written.",
                'next_command' => 'doctor --family=workspace --fix',
            ];
        } else {
            try {
                $setup = $this->setupWorkspace->handle($app, $workspace, $node);
                $warnings = array_merge($warnings, $setup['warnings']);
                $httpProbe = $setup['http_probe'];
            } catch (RuntimeException $exception) {
                throw new WorkspaceCreateFailed(
                    'workspace.enactment_failed',
                    "Workspace enactment on node '{$node->name}' stopped before Orbit could classify remaining drift.",
                    [
                        'step' => 'setup_pipeline',
                        'node' => $node->name,
                        'reason' => $exception->getMessage(),
                    ],
                );
            }
        }

        $workspace->refresh();
        $workspace->setRelation('app', $app);

        return [
            'result' => ['action' => 'created'],
            'workspace' => $this->workspacePayload($workspace, $app, $node),
            'meta' => [
                'node' => $node->name,
                'base' => $base,
                'http_probe' => $httpProbe,
                'warnings' => $warnings,
            ],
        ];
    }

    private function workspacePath(App $app, string $workspaceName): string
    {
        return rtrim($app->path, '/').'/'.$workspaceName;
    }

    private function worktreeScript(App $app, Workspace $workspace, string $base): string
    {
        return sprintf(
            <<<'SH'
set -Eeuo pipefail
app_path=%s
workspace_path=%s
base_ref=%s

if [ -e "$workspace_path" ]; then
    echo "workspace path already exists: $workspace_path" >&2
    exit 2
fi

mkdir -p "$(dirname "$workspace_path")"
git -C "$app_path" worktree add --detach "$workspace_path" "$base_ref"
SH,
            escapeshellarg($app->path),
            escapeshellarg($workspace->path),
            escapeshellarg($base),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(Workspace $workspace, App $app, Node $node): array
    {
        return [
            'name' => $workspace->name,
            'app' => $app->name,
            'node' => $node->name,
            'path' => $workspace->path,
            'url' => $workspace->url(),
            'php_version' => $workspace->effectivePhpVersion(),
            'php_inherited' => $workspace->php_version === null,
            'adopted' => false,
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];
    }
}
