<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\RemoteShell;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Services\CodexApp\CodexAppConfigMerger;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AppCodexController
{
    private const string ConfigPath = '~/.codex/codex-app/config.json';

    private const string ApplyUrl = 'codex://codex-app/apply-config';

    public function add(
        Request $request,
        string $app,
        RemoteShell $remoteShell,
        CodexAppConfigMerger $merger,
        ToolCatalog $catalog,
    ): JsonResponse {
        $context = $this->mutationContext($request, $app, $catalog);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$model, $target] = $context;
        $config = $this->readConfig($remoteShell, $target);

        if ($config instanceof JsonResponse) {
            return $config;
        }

        $project = $this->projectPayload($model);
        $config = $merger->addProject(
            $config,
            $project['label'],
            $project['ssh_alias'],
            $project['remote_path'],
        );

        $write = $this->writeConfig($remoteShell, $target, $config);

        if ($write instanceof JsonResponse) {
            return $write;
        }

        $warnings = $this->applyConfig($remoteShell, $target);

        return $this->success([
            'codex_project' => [
                ...$project,
                'node' => $target->name,
                'added' => true,
            ],
        ], $warnings);
    }

    public function remove(
        Request $request,
        string $app,
        RemoteShell $remoteShell,
        CodexAppConfigMerger $merger,
        ToolCatalog $catalog,
    ): JsonResponse {
        $context = $this->mutationContext($request, $app, $catalog);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$model, $target] = $context;
        $config = $this->readConfig($remoteShell, $target);

        if ($config instanceof JsonResponse) {
            return $config;
        }

        $project = $this->projectPayload($model);
        $removed = $merger->hasProject($config, $project['label'], $project['ssh_alias']);
        $config = $merger->removeProject($config, $project['label'], $project['ssh_alias']);

        $write = $this->writeConfig($remoteShell, $target, $config);

        if ($write instanceof JsonResponse) {
            return $write;
        }

        $warnings = $this->applyConfig($remoteShell, $target);

        return $this->success([
            'codex_project' => [
                ...$project,
                'node' => $target->name,
                'removed' => $removed,
            ],
        ], $warnings);
    }

    public function list(
        Request $request,
        RemoteShell $remoteShell,
        CodexAppConfigMerger $merger,
        ToolCatalog $catalog,
    ): JsonResponse {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $target = $this->targetNode($request, $caller, 'app:codex');

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $unsupported = $this->unsupportedTarget($catalog, $target);

        if ($unsupported instanceof JsonResponse) {
            return $unsupported;
        }

        $config = $this->readConfig($remoteShell, $target);

        if ($config instanceof JsonResponse) {
            return $config;
        }

        $projects = [];

        foreach ($merger->remoteConnections($config) as $connection) {
            foreach ($merger->projects($connection) as $project) {
                $projects[] = $this->projectPayloadFromConfig($connection, $project);
            }
        }

        return $this->success([
            'codex_projects' => $projects,
        ]);
    }

    /**
     * @return array{0: OrbitApp, 1: Node}|JsonResponse
     */
    private function mutationContext(Request $request, string $app, ToolCatalog $catalog): array|JsonResponse
    {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $model = $this->resolveApp($app);

        if (! $model instanceof OrbitApp || ! $model->node instanceof Node) {
            return $this->error('app.not_found', "App '{$app}' not found.", ['app' => $app], 404);
        }

        if (! app(NodeAccessAuthorizer::class)->allows($caller, $model->node, 'app:codex')) {
            return $this->error(
                'authorization_failed',
                "This node is not authorized for 'app:codex' on '{$model->node?->name}'.",
                [
                    'serving_node' => $model->node?->name,
                    'missing_permission' => 'app:codex',
                ],
                403,
            );
        }

        $target = $this->targetNode($request, $caller, 'app:codex');

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $unsupported = $this->unsupportedTarget($catalog, $target);

        if ($unsupported instanceof JsonResponse) {
            return $unsupported;
        }

        return [$model, $target];
    }

    private function targetNode(Request $request, Node $caller, string $permission): Node|JsonResponse
    {
        $node = $this->stringInput($request, 'node');

        if ($node === null) {
            return $this->error('validation_failed', 'Node is required.', ['field' => 'node'], 422);
        }

        $target = Node::query()
            ->where('name', $node)
            ->where('status', 'active')
            ->first();

        if (! $target instanceof Node) {
            return $this->error(
                'validation_failed',
                "Invalid value for --node: '{$node}'. Expected an active visible node name.",
                [
                    'field' => 'node',
                    'value' => $node,
                ],
                422,
            );
        }

        if (app(NodeRoleAssignments::class)->nodeIsGateway($target)) {
            return $this->error(
                'validation_failed',
                "Invalid value for --node: '{$node}'. Gateway nodes are not Codex App targets.",
                [
                    'field' => 'node',
                    'value' => $node,
                    'reason' => 'gateway_not_tool_eligible',
                ],
                422,
            );
        }

        if (! app(NodeAccessAuthorizer::class)->allows($caller, $target, $permission)) {
            return $this->error(
                'authorization_failed',
                "This node is not authorized for '{$permission}' on '{$target->name}'.",
                [
                    'serving_node' => $target->name,
                    'missing_permission' => $permission,
                ],
                403,
            );
        }

        return $target;
    }

    private function unsupportedTarget(ToolCatalog $catalog, Node $target): ?JsonResponse
    {
        if ($catalog->supportsNode('codex-app', $target)) {
            return null;
        }

        return $this->error(
            'tool.unsupported_on_node',
            "Tool 'codex-app' does not support node '{$target->name}' platform.",
            [
                'tool' => 'codex-app',
                'node' => $target->name,
                'platform' => $target->platform,
                'supported_operating_systems' => $catalog->supportedOperatingSystems('codex-app'),
            ],
            422,
        );
    }

    private function readConfig(RemoteShell $remoteShell, Node $target): array|JsonResponse
    {
        $result = $remoteShell->run(
            $target,
            'if [ -f '.self::ConfigPath.' ]; then cat '.self::ConfigPath."; else printf '{}'; fi",
            ['throw' => false],
        );

        if (! $result->successful()) {
            return $this->error(
                'codex_app.config_read_failed',
                "Codex App config could not be read on node '{$target->name}'.",
                [
                    'node' => $target->name,
                    'path' => self::ConfigPath,
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
                502,
            );
        }

        $json = trim($result->stdout) !== '' ? $result->stdout : '{}';
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $this->error(
                'codex_app.config_read_failed',
                "Codex App config is not valid JSON on node '{$target->name}'.",
                [
                    'node' => $target->name,
                    'path' => self::ConfigPath,
                    'json_error' => json_last_error_msg(),
                ],
                422,
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeConfig(RemoteShell $remoteShell, Node $target, array $config): ?JsonResponse
    {
        $result = $remoteShell->run(
            $target,
            <<<'BASH'
                set -e
                config="$HOME/.codex/codex-app/config.json"
                dir="$(dirname "$config")"
                mkdir -p "$dir"
                chmod 700 "$dir"
                tmp="$(mktemp "$dir/config.json.XXXXXX")"
                trap 'rm -f "$tmp"' EXIT
                cat > "$tmp"
                chmod 600 "$tmp"
                mv "$tmp" "$config"
                trap - EXIT
                BASH,
            [
                'throw' => false,
                'input' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n",
            ],
        );

        if ($result->successful()) {
            return null;
        }

        return $this->error(
            'codex_app.config_write_failed',
            "Codex App config write failed on node '{$target->name}'.",
            [
                'node' => $target->name,
                'path' => self::ConfigPath,
                'exit_code' => $result->exitCode,
                'stderr' => trim($result->stderr),
            ],
            502,
        );
    }

    /**
     * @return list<array{code: string, message: string, meta: array<string, mixed>}>
     */
    private function applyConfig(RemoteShell $remoteShell, Node $target): array
    {
        $result = $remoteShell->run($target, 'open '.escapeshellarg(self::ApplyUrl), ['throw' => false]);

        if ($result->successful()) {
            return [];
        }

        return [[
            'code' => 'codex_app.apply_failed',
            'message' => 'Codex App config was written, but the apply callback failed.',
            'meta' => [
                'node' => $target->name,
                'exit_code' => $result->exitCode,
                'stderr' => trim($result->stderr),
            ],
        ]];
    }

    private function resolveApp(string $selector): ?OrbitApp
    {
        return OrbitApp::query()
            ->with('node')
            ->where(function ($query) use ($selector): void {
                $query->where('name', $selector)
                    ->orWhere('domain', $selector);
            })
            ->first();
    }

    /**
     * @return array{app: string, label: string, ssh_alias: string, remote_path: string}
     */
    private function projectPayload(OrbitApp $app): array
    {
        return [
            'app' => $app->name,
            'label' => $app->name,
            'ssh_alias' => $this->sshAlias($app),
            'remote_path' => rtrim($app->path, '/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $project
     * @return array{app: string, label: string, ssh_alias: string, remote_path: string}
     */
    private function projectPayloadFromConfig(array $connection, array $project): array
    {
        $label = (string) ($project['label'] ?? '');

        return [
            'app' => $label,
            'label' => $label,
            'ssh_alias' => (string) ($connection['sshAlias'] ?? ''),
            'remote_path' => (string) ($project['remotePath'] ?? ''),
        ];
    }

    private function sshAlias(OrbitApp $app): string
    {
        return $app->node instanceof Node ? $app->node->name : $app->name;
    }

    private function caller(Request $request): ?Node
    {
        $caller = $request->user();

        return $caller instanceof Node ? $caller : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{code: string, message: string, meta: array<string, mixed>}>  $warnings
     */
    private function success(array $data, array $warnings = []): JsonResponse
    {
        $meta = $warnings === [] ? (object) [] : ['warnings' => $warnings];

        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
    }
}
