<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final readonly class RunWorkspaceCommand
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @param  list<string>  $command
     * @return array{
     *     workspace: string,
     *     app: string,
     *     container: string,
     *     command: list<string>,
     *     exit_code: int,
     *     stdout: string,
     *     stderr: string
     * }
     */
    public function handle(Workspace $workspace, array $command): array
    {
        if ($command === []) {
            $this->fail('validation_failed', 'A command to execute is required.', ['field' => 'command']);
        }

        $workspace->loadMissing('app.node');
        $app = $workspace->app;

        if (! $app instanceof App) {
            $this->fail(
                'workspace.exec_container_not_running',
                "Workspace '{$workspace->name}' has no parent app; cannot resolve runtime container.",
                ['workspace' => $workspace->name],
            );
        }

        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            $this->fail(
                'workspace.exec_unsupported_runtime',
                "Workspace '{$workspace->name}' parent app has runtime_kind={$app->runtime_kind->value}; workspace:exec requires runtime_kind=php.",
                [
                    'workspace' => $workspace->name,
                    'app' => $app->name,
                    'runtime_kind' => $app->runtime_kind->value,
                ],
            );
        }

        $container = "orbit-ws-{$app->name}-{$workspace->name}";
        $node = $app->node;

        if (! $node instanceof Node) {
            $this->fail(
                'workspace.exec_container_not_running',
                "Workspace '{$workspace->name}' parent app has no owning node; cannot resolve runtime container.",
                [
                    'workspace' => $workspace->name,
                    'app' => $app->name,
                    'container' => $container,
                ],
            );
        }

        $preflight = $this->remoteShell->run(
            $node,
            'docker container inspect --format '.escapeshellarg('{{.State.Running}}').' '.escapeshellarg($container),
        );

        $failure = $this->classifyPreflightFailure($preflight, $workspace->name, $app->name, $container, $node);

        if ($failure !== null) {
            $this->fail($failure['code'], $failure['message'], $failure['meta']);
        }

        $result = $this->remoteShell->run($node, $this->dockerExecScript($container, $command));

        $execFailure = $this->classifyExecFailure($result, $workspace->name, $app->name, $container, $node);

        if ($execFailure !== null) {
            $this->fail($execFailure['code'], $execFailure['message'], $execFailure['meta']);
        }

        return [
            'workspace' => $workspace->name,
            'app' => $app->name,
            'container' => $container,
            'command' => $command,
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function dockerExecScript(string $container, array $command): string
    {
        $parts = ['docker', 'exec', $container, ...$command];

        return implode(' ', array_map(escapeshellarg(...), $parts));
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    private function classifyPreflightFailure(RemoteShellResult $result, string $workspace, string $app, string $container, Node $node): ?array
    {
        if ($result->successful() && trim($result->stdout) === 'true') {
            return null;
        }

        $haystack = $result->stderr.' '.$result->stdout;

        if ($result->successful()) {
            return [
                'code' => 'workspace.exec_container_not_running',
                'message' => "Workspace '{$workspace}' runtime container is not running on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'app' => $app,
                    'container' => $container,
                    'node' => $node->name,
                    'state' => trim($result->stdout),
                ],
            ];
        }

        if ($this->looksLikeDockerDaemonDown($haystack)) {
            return [
                'code' => 'workspace.exec_docker_unavailable',
                'message' => "Docker daemon is unavailable on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'node' => $node->name,
                    'stderr' => trim($result->stderr),
                ],
            ];
        }

        if ($this->looksLikeContainerMissing($haystack)) {
            return [
                'code' => 'workspace.exec_container_not_running',
                'message' => "Workspace '{$workspace}' runtime container is not running on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'app' => $app,
                    'container' => $container,
                    'node' => $node->name,
                ],
            ];
        }

        return [
            'code' => 'workspace.exec_node_unreachable',
            'message' => "Cannot reach node '{$node->name}' to run workspace:exec.",
            'meta' => [
                'workspace' => $workspace,
                'node' => $node->name,
                'exit_code' => $result->exitCode,
                'stderr' => trim($result->stderr),
            ],
        ];
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    private function classifyExecFailure(RemoteShellResult $result, string $workspace, string $app, string $container, Node $node): ?array
    {
        if (! in_array($result->exitCode, [125, 126, 127], true)) {
            return null;
        }

        $haystack = $result->stderr.' '.$result->stdout;

        if ($result->exitCode === 126) {
            return [
                'code' => 'workspace.exec_command_not_executable',
                'message' => "Command is not executable inside workspace '{$workspace}' runtime container on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'app' => $app,
                    'container' => $container,
                    'node' => $node->name,
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
            ];
        }

        if ($result->exitCode === 127) {
            return [
                'code' => 'workspace.exec_command_not_found',
                'message' => "Command not found inside workspace '{$workspace}' runtime container on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'app' => $app,
                    'container' => $container,
                    'node' => $node->name,
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
            ];
        }

        if ($this->looksLikeContainerMissing($haystack)) {
            return [
                'code' => 'workspace.exec_container_not_running',
                'message' => "Workspace '{$workspace}' runtime container is not running on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'app' => $app,
                    'container' => $container,
                    'node' => $node->name,
                ],
            ];
        }

        if ($this->looksLikeDockerDaemonDown($haystack)) {
            return [
                'code' => 'workspace.exec_docker_unavailable',
                'message' => "Docker daemon is unavailable on node '{$node->name}'.",
                'meta' => [
                    'workspace' => $workspace,
                    'node' => $node->name,
                    'stderr' => trim($result->stderr),
                ],
            ];
        }

        return null;
    }

    private function looksLikeDockerDaemonDown(string $haystack): bool
    {
        return preg_match('/Cannot connect to the Docker daemon|docker daemon (?:is not running|running)/i', $haystack) === 1;
    }

    private function looksLikeContainerMissing(string $haystack): bool
    {
        return preg_match('/No such container|No such object|is not running/i', $haystack) === 1;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta): never
    {
        throw new GatewayApiException($message, $code, $meta);
    }
}
