<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\WorkspaceExecRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceExecResponse;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Runtime\OrbitHostCwdResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Signature('workspace:exec
    {workspace? : Workspace name}
    {cmd?* : Command to run inside the workspace runtime container}
    {--app= : Parent app slug to disambiguate workspace names that collide across apps}
    {--json : Output JSON}')]
#[Description('Run a command inside a workspace FrankenPHP runtime container')]
class WorkspaceExecCommand extends Command
{
    public function handle(RemoteShell $remoteShell, OrbitHostCwdResolver $cwdResolver): int
    {
        $command = $this->commandTokens();

        if ($command === []) {
            return $this->failValidation('command', 'A command to execute is required.');
        }

        $selector = $this->stringArgument('workspace');
        $appFilter = $this->stringOption('app');
        $hostCwd = $this->hostCwdFromEnv();

        if (! (bool) config('orbit.is_gateway', false)) {
            // Control mode: forward raw selector, --app filter, and raw host
            // cwd to the gateway. Do not touch local Workspace/App rows.
            if ($selector === null && $hostCwd === null) {
                return $this->failValidation('workspace', 'Workspace is required.');
            }

            return $this->forwardExec($selector, $appFilter, $hostCwd, $command);
        }

        // Gateway mode: resolve locally.
        if ($selector === null) {
            $context = $cwdResolver->resolve($hostCwd);

            if ($context?->workspace instanceof Workspace) {
                $selector = $context->workspace->name;
                $appFilter ??= $context->workspace->app?->name;
            }
        }

        if ($selector === null) {
            return $this->failValidation('workspace', 'Workspace is required.');
        }

        $matches = $this->matchingWorkspaces($selector, $appFilter);

        if ($matches === []) {
            return $this->failCommand(
                code: 'workspace.not_found',
                message: "Workspace '{$selector}' not found.",
                meta: array_filter([
                    'workspace' => $selector,
                    'app' => $appFilter,
                ], fn ($value): bool => $value !== null),
            );
        }

        if ($appFilter === null && count($matches) > 1) {
            return $this->failCommand(
                code: 'workspace.ambiguous_name',
                message: "Workspace name '{$selector}' matches multiple apps; pass --app to disambiguate.",
                meta: [
                    'workspace' => $selector,
                    'apps' => array_values(array_filter(array_map(
                        fn (Workspace $workspace): ?string => $workspace->app?->name,
                        $matches,
                    ))),
                ],
            );
        }

        return $this->execIn($remoteShell, $matches[0], $command);
    }

    /**
     * @param  list<string>  $command
     */
    private function execIn(RemoteShell $remoteShell, Workspace $workspace, array $command): int
    {
        $workspace->loadMissing('app.node');
        $app = $workspace->app;

        if ($app === null) {
            return $this->failCommand(
                code: 'workspace.exec_container_not_running',
                message: "Workspace '{$workspace->name}' has no parent app; cannot resolve runtime container.",
                meta: [
                    'workspace' => $workspace->name,
                ],
            );
        }

        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return $this->failCommand(
                code: 'workspace.exec_unsupported_runtime',
                message: "Workspace '{$workspace->name}' parent app has runtime_kind={$app->runtime_kind->value}; workspace:exec requires runtime_kind=php.",
                meta: [
                    'workspace' => $workspace->name,
                    'app' => $app->name,
                    'runtime_kind' => $app->runtime_kind->value,
                ],
            );
        }

        $container = "orbit-ws-{$app->name}-{$workspace->name}";
        $node = $app->node;

        if ($node === null) {
            return $this->failCommand(
                code: 'workspace.exec_container_not_running',
                message: "Workspace '{$workspace->name}' parent app has no owning node; cannot resolve runtime container.",
                meta: [
                    'workspace' => $workspace->name,
                    'app' => $app->name,
                    'container' => $container,
                ],
            );
        }

        $preflight = $remoteShell->run(
            $node,
            'docker container inspect --format '.escapeshellarg('{{.State.Running}}').' '.escapeshellarg($container),
        );

        $failure = $this->classifyPreflightFailure($preflight, $workspace->name, $app->name, $container, $node);

        if ($failure !== null) {
            return $this->failCommand(
                code: $failure['code'],
                message: $failure['message'],
                meta: $failure['meta'],
            );
        }

        $result = $remoteShell->run($node, $this->dockerExecScript($container, $command));

        $execFailure = $this->classifyExecFailure($result, $workspace->name, $app->name, $container, $node);

        if ($execFailure !== null) {
            return $this->failCommand(
                code: $execFailure['code'],
                message: $execFailure['message'],
                meta: $execFailure['meta'],
            );
        }

        return $this->emitSuccess([
            'workspace' => $workspace->name,
            'app' => $app->name,
            'container' => $container,
            'command' => $command,
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
        ], $result);
    }

    /**
     * @return list<string>
     */
    private function commandTokens(): array
    {
        $raw = $this->argument('cmd');

        if (! is_array($raw)) {
            return [];
        }

        $tokens = [];

        foreach ($raw as $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
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
     * Classify a `docker exec` result. Docker reserves a small block of
     * exit codes for wrapper-side failures:
     *
     * - `125`: docker daemon itself failed before the child ran (container
     *   vanished, daemon went down, image gone).
     * - `126`: the exec target was located inside the container but is
     *   not executable (permission denied, wrong architecture, etc).
     * - `127`: the exec target was not found inside the container.
     *
     * Any other non-zero exit is the child command's own exit code and
     * must NOT be classified as infra failure — stderr signatures inside
     * the child's output are not authoritative.
     *
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

        // exitCode === 125: docker wrapper failure — disambiguate via stderr.
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
     * @param  list<string>  $command
     */
    private function forwardExec(?string $selector, ?string $appFilter, ?string $hostCwd, array $command): int
    {
        try {
            /** @var WorkspaceExecResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new WorkspaceExecRequest(
                    workspace: $selector,
                    command: $command,
                    app: $appFilter,
                    hostCwd: $hostCwd,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to run workspace:exec.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to run workspace:exec.',
                meta: [],
            );
        }

        return $this->emitForwardedSuccess($dto->data);
    }

    private function hostCwdFromEnv(): ?string
    {
        $value = getenv('ORBIT_HOST_CWD');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<Workspace>
     */
    private function matchingWorkspaces(string $name, ?string $app): array
    {
        return Workspace::query()
            ->with('app.node')
            ->where('name', $name)
            ->when(
                $app !== null,
                fn ($query) => $query->whereHas('app', fn ($q) => $q->where('name', $app)),
            )
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emitSuccess(array $data, RemoteShellResult $result): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->writePassthrough($result->stdout, $result->stderr);

        return $result->exitCode;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emitForwardedSuccess(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';
        $stderr = is_string($data['stderr'] ?? null) ? $data['stderr'] : '';
        $exitCode = is_int($data['exit_code'] ?? null) ? $data['exit_code'] : 0;

        $this->writePassthrough($stdout, $stderr);

        return $exitCode;
    }

    /**
     * Write the child command's stdout and stderr to the matching streams
     * on the parent process. When the underlying output supports a split
     * error stream (real terminals, CommandTester with
     * `capture_stderr_separately`) we use it so shell pipelines that
     * branch on stderr keep working. Tests that capture both into a
     * single buffer still see the combined output.
     */
    private function writePassthrough(string $stdout, string $stderr): void
    {
        if ($stdout !== '') {
            $this->output->write($stdout);
        }

        if ($stderr === '') {
            return;
        }

        $errorStream = $this->errorStream();

        if ($errorStream !== null) {
            $errorStream->write($stderr);

            return;
        }

        $this->output->write($stderr);
    }

    private function errorStream(): ?OutputInterface
    {
        $underlying = method_exists($this->output, 'getOutput')
            ? $this->output->getOutput()
            : null;

        if ($underlying instanceof ConsoleOutputInterface) {
            return $underlying->getErrorOutput();
        }

        return null;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: array_merge(['field' => $field], $extraMeta),
        );
    }
}
