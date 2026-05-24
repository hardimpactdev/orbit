<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\WorkspaceSourceDriver;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use Polyscope\Laravel\Polyscope;
use Throwable;

final readonly class PolyscopeWorkspaceDriver implements WorkspaceSourceDriver
{
    public function __construct(
        private PolyscopeWorkspaceBranchAligner $branchAligner,
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $config = $this->resolveConfig($app, $node);
        $client = new Polyscope($config['api_token'], baseUrl: $config['base_url']);

        try {
            $workspace = $client->createWorkspace([
                'server_id' => $config['server_id'],
                'repository_id' => $config['repository_id'],
                'branch' => $name,
                'base_branch' => $base,
            ]);
        } catch (Throwable $exception) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope could not create the workspace.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        if (! is_string($workspace->id) || $workspace->id === '') {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope did not return a workspace id.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        if (! is_string($workspace->path) || $workspace->path === '') {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope did not return a workspace path.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        if ($workspace->branch !== $name) {
            try {
                $this->branchAligner->align($node, $workspace->id, $workspace->path, $name);
            } catch (WorkspaceCreateFailed $exception) {
                try {
                    $client->deleteWorkspace($workspace->id);
                } catch (Throwable) {
                    // Best-effort cleanup after a post-create alignment failure.
                }

                throw $exception;
            }
        }

        return new WorkspaceProvisionResult(
            name: $name,
            path: $workspace->path,
            agentIde: 'polyscope',
            agentIdeWorkspaceId: $workspace->id,
        );
    }

    /**
     * @return array{api_token: string, server_id: string, repository_id: string, base_url: string|null}
     */
    private function resolveConfig(App $app, Node $node): array
    {
        $nodeConfig = is_array($node->agent_ide_config) ? $node->agent_ide_config : [];
        $appConfig = is_array($app->agent_ide_config) ? $app->agent_ide_config : [];
        $polyscopeNodeConfig = is_array($nodeConfig['polyscope'] ?? null) ? $nodeConfig['polyscope'] : [];
        $polyscopeAppConfig = is_array($appConfig['polyscope'] ?? null) ? $appConfig['polyscope'] : [];

        $config = [
            'api_token' => $this->stringValue($polyscopeNodeConfig['api_token'] ?? null)
                ?? $this->stringValue($polyscopeNodeConfig['api_key'] ?? null)
                ?? $this->stringValue($polyscopeNodeConfig['auth_token'] ?? null),
            'server_id' => $this->stringValue($polyscopeNodeConfig['server_id'] ?? null),
            'repository_id' => $this->stringValue($polyscopeAppConfig['repository_id'] ?? null),
            'base_url' => $this->stringValue($polyscopeNodeConfig['base_url'] ?? null),
        ];

        if ($config['api_token'] !== null && $config['server_id'] !== null && $config['repository_id'] !== null) {
            return $config;
        }

        $remoteConfig = $this->readRemoteConfig($app, $node);

        $config = [
            'api_token' => $config['api_token'] ?? $remoteConfig['api_token'],
            'server_id' => $config['server_id'] ?? $remoteConfig['server_id'],
            'repository_id' => $config['repository_id'] ?? $remoteConfig['repository_id'],
            'base_url' => $config['base_url'] ?? $remoteConfig['base_url'],
        ];

        if ($config['api_token'] === null || $config['server_id'] === null || $config['repository_id'] === null) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope is not configured for this app node and repository.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'missing' => array_values(array_filter([
                        $config['api_token'] === null ? 'api_token' : null,
                        $config['server_id'] === null ? 'server_id' : null,
                        $config['repository_id'] === null ? 'repository_id' : null,
                    ])),
                ],
            );
        }

        return $config;
    }

    /**
     * @return array{api_token: string|null, server_id: string|null, repository_id: string|null, base_url: string|null}
     */
    private function readRemoteConfig(App $app, Node $node): array
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            commandOptions: [
                'adapter' => 'polyscope',
                'lookup' => 'config',
                'app-path' => $app->path,
            ],
            transportOptions: [
                'timeout' => 30,
                'redact_stdout' => true,
                'redact_stderr' => true,
            ],
        );

        if (! $result->successful()) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope configuration could not be read from the app node.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'reason' => $this->failureReason($result),
                ],
            );
        }

        $payload = $this->successPayload($result, $app, $node);

        return [
            'api_token' => $this->stringValue($payload['api_token'] ?? null),
            'server_id' => $this->stringValue($payload['server_id'] ?? null),
            'repository_id' => $this->stringValue($payload['repository_id'] ?? null),
            'base_url' => $this->stringValue($payload['base_url'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(RemoteShellResult $result, App $app, Node $node): array
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope configuration returned by the app node was invalid.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        if (! is_array($decoded) || ($decoded['ok'] ?? null) !== true || ! is_array($decoded['data'] ?? null)) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope configuration returned by the app node was invalid.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        return $decoded['data'];
    }

    private function failureReason(RemoteShellResult $result): string
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return trim($result->stderr) ?: trim($result->stdout);
        }

        $message = is_array($decoded)
            && is_array($decoded['error'] ?? null)
            && is_string($decoded['error']['message'] ?? null)
            ? trim($decoded['error']['message'])
            : '';

        return $message !== '' ? $message : (trim($result->stderr) ?: trim($result->stdout));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
