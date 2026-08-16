<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Services\CodexApp\CodexAppConfigMerger;
use App\Services\CodexApp\RemoteCodexAppConfig;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolCatalog;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CodexAppController
{
    private const string ConfigPath = '~/.codex/codex-app/config.json';

    public function add(
        Request $request,
        string $project,
        RemoteCodexAppConfig $codexConfig,
        ToolCatalog $catalog,
    ): JsonResponse {
        $context = $this->mutationContext($request, $project, $catalog);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$model, $target] = $context;
        $project = $this->projectPayload($model);
        $mutation = $codexConfig->mutate($target, 'add', [
            'label' => $project['label'],
            'ssh_alias' => $project['ssh_alias'],
            'remote_path' => $project['remote_path'],
        ]);

        if (! $mutation->successful()) {
            return $this->mutationFailure($codexConfig, $mutation, $target);
        }

        return $this->success(
            [
                'codex_project' => [
                    ...$project,
                    'node' => $target->name,
                    'added' => true,
                ],
            ],
            $this->mutationWarnings($codexConfig, $mutation, $target),
        );
    }

    public function remove(
        Request $request,
        string $project,
        RemoteCodexAppConfig $codexConfig,
        ToolCatalog $catalog,
    ): JsonResponse {
        $context = $this->mutationContext($request, $project, $catalog);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$model, $target] = $context;
        $project = $this->projectPayload($model);
        $mutation = $codexConfig->mutate($target, 'remove', [
            'label' => $project['label'],
            'ssh_alias' => $project['ssh_alias'],
            'remote_path' => $project['remote_path'],
        ]);

        if (! $mutation->successful()) {
            return $this->mutationFailure($codexConfig, $mutation, $target);
        }

        $removed = ($codexConfig->data($mutation)['removed'] ?? false) === true;

        return $this->success(
            [
                'codex_project' => [
                    ...$project,
                    'node' => $target->name,
                    'removed' => $removed,
                ],
            ],
            $this->mutationWarnings($codexConfig, $mutation, $target),
        );
    }

    public function list(
        Request $request,
        RemoteCodexAppConfig $codexConfig,
        CodexAppConfigMerger $merger,
        ToolCatalog $catalog,
    ): JsonResponse {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $target = $this->targetNode($request, $caller, 'codex:app');

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $unsupported = $this->unsupportedTarget($catalog, $target);

        if ($unsupported instanceof JsonResponse) {
            return $unsupported;
        }

        $config = $this->readConfig($codexConfig, $target);

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
     * @return array{0: App, 1: Node}|JsonResponse
     */
    private function mutationContext(Request $request, string $project, ToolCatalog $catalog): array|JsonResponse
    {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $model = $this->resolveApp($project);

        if (! $model instanceof App) {
            return $this->error('project.not_found', "Project '{$project}' not found.", ['project' => $project], 404);
        }

        // App owns no node: the serving node comes from the app's primary instance.
        $servingNode = app(WorkspacePlacement::class)->runtimeNode($model, null);

        if (! $servingNode instanceof Node) {
            return $this->error('project.not_found', "Project '{$project}' not found.", ['project' => $project], 404);
        }

        if (! app(NodeAccessAuthorizer::class)->allows($caller, $servingNode, 'codex:app')) {
            return $this->error(
                'authorization_failed',
                "This node is not authorized for 'codex:app' on '{$servingNode->name}'.",
                [
                    'serving_node' => $servingNode->name,
                    'missing_permission' => 'codex:app',
                ],
                403,
            );
        }

        $target = $this->targetNode($request, $caller, 'codex:app');

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

    private function readConfig(RemoteCodexAppConfig $codexConfig, Node $target): array|JsonResponse
    {
        $result = $codexConfig->read($target);

        if (! $result->successful()) {
            return $this->error(
                'app_codex.config_read_failed',
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

        $contents = $codexConfig->data($result)['contents'] ?? null;
        $json = is_string($contents) && trim($contents) !== '' ? $contents : '{}';
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $this->error(
                'app_codex.config_read_failed',
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

    private function mutationFailure(
        RemoteCodexAppConfig $codexConfig,
        RemoteShellResult $result,
        Node $target,
    ): JsonResponse {
        $error = $codexConfig->error($result);
        $code = is_string($error['code'] ?? null) ? $error['code'] : '';
        $errorMeta = is_array($error['meta'] ?? null) ? $error['meta'] : [];

        if ($code === 'codex_app.config_read_failed') {
            $invalid = array_key_exists('json_error', $errorMeta);

            return $this->error(
                'app_codex.config_read_failed',
                $invalid
                    ? "Codex App config is not valid JSON on node '{$target->name}'."
                    : "Codex App config could not be read on node '{$target->name}'.",
                [
                    'node' => $target->name,
                    'path' => self::ConfigPath,
                    ...($invalid ? ['json_error' => $errorMeta['json_error']] : []),
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
                $invalid ? 422 : 502,
            );
        }

        if ($code === 'codex_app.config_lock_failed') {
            return $this->error(
                'app_codex.config_lock_failed',
                "Codex App config lock failed on node '{$target->name}'.",
                [
                    'node' => $target->name,
                    'path' => self::ConfigPath,
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
                502,
            );
        }

        return $this->error(
            'app_codex.config_write_failed',
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
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function mutationWarnings(
        RemoteCodexAppConfig $codexConfig,
        RemoteShellResult $result,
        Node $target,
    ): array {
        $warnings = $codexConfig->meta($result)['warnings'] ?? [];
        $normalized = [];

        if (! is_array($warnings)) {
            return [];
        }

        foreach ($warnings as $warning) {
            if (! is_array($warning) || ($warning['code'] ?? null) !== 'codex_app.apply_failed') {
                continue;
            }

            $meta = is_array($warning['meta'] ?? null) ? $warning['meta'] : [];
            $normalized[] = [
                'code' => 'app_codex.apply_failed',
                'message' => 'Codex App config was written, but the apply callback failed.',
                'meta' => [
                    'node' => $target->name,
                    'exit_code' => $meta['exit_code'] ?? null,
                    'stderr' => is_string($meta['stderr'] ?? null) ? trim($meta['stderr']) : '',
                ],
            ];
        }

        return $normalized;
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->where(function ($query) use ($selector): void {
                $query->where('name', $selector)
                    ->orWhere('domain', $selector);
            })
            ->first();
    }

    /**
     * @return array{project: string, label: string, ssh_alias: string, remote_path: string}
     */
    private function projectPayload(App $app): array
    {
        $placement = app(WorkspacePlacement::class);
        $instance = $placement->appPrimaryInstance($app);
        $node = $instance !== null ? $placement->nodeForInstance($instance) : null;

        return [
            'project' => $app->name,
            'label' => $app->name,
            'ssh_alias' => $node instanceof Node ? $node->name : $app->name,
            'remote_path' => $instance !== null ? rtrim($placement->runtimePath($app, $instance), '/') : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $project
     * @return array{project: string, label: string, ssh_alias: string, remote_path: string}
     */
    private function projectPayloadFromConfig(array $connection, array $project): array
    {
        $label = (string) ($project['label'] ?? '');

        return [
            'project' => $label,
            'label' => $label,
            'ssh_alias' => (string) ($connection['sshAlias'] ?? ''),
            'remote_path' => (string) ($project['remotePath'] ?? ''),
        ];
    }

    private function caller(Request $request): ?Node
    {
        $caller = $request->user();

        return $caller instanceof Node ? $caller : null;
    }

    /** @mago-expect analysis:mixed-assignment */
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
