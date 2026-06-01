<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeUser;

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
     *     php_version: string,
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
                'workspace.exec_source_missing',
                "Workspace '{$workspace->name}' has no parent app; cannot run command on host.",
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

        $node = $app->node;

        if (! $node instanceof Node) {
            $this->fail(
                'workspace.exec_source_missing',
                "Workspace '{$workspace->name}' parent app has no owning node; cannot run command on host.",
                [
                    'workspace' => $workspace->name,
                    'app' => $app->name,
                ],
            );
        }

        $sourcePath = rtrim((string) $workspace->path, '/');

        if ($sourcePath === '') {
            $this->fail(
                'workspace.exec_source_missing',
                "Workspace '{$workspace->name}' has no source path configured.",
                ['workspace' => $workspace->name, 'app' => $app->name],
            );
        }

        $phpVersion = $workspace->effectivePhpVersion() ?? $app->php_version;
        $runtimeUser = app(AppRuntimeUser::class)->forApp($app);

        $result = $this->remoteShell->run($node, $this->hostExecScript($sourcePath, $phpVersion, $runtimeUser, $command));

        return [
            'workspace' => $workspace->name,
            'app' => $app->name,
            'php_version' => $phpVersion,
            'command' => $command,
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
        ];
    }

    /**
     * Build a host-side exec script that runs the given command from the
     * workspace source path with the version-matched PHP binary first on PATH.
     *
     * Shape:
     *   sudo -u <runtimeUser> -H bash -lc 'cd <sourcePath> && PATH=/opt/orbit/php/<ver>/bin:$PATH <cmd> ...'
     *
     * @param  list<string>  $command
     */
    private function hostExecScript(string $sourcePath, string $phpVersion, string $runtimeUser, array $command): string
    {
        $inner = 'cd '.escapeshellarg($sourcePath)
            .' && PATH=/opt/orbit/php/'.escapeshellarg($phpVersion).'/bin:$PATH '
            .implode(' ', array_map(escapeshellarg(...), $command));

        return implode(' ', array_map(escapeshellarg(...), ['sudo', '-u', $runtimeUser, '-H', 'bash', '-lc', $inner]));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta): never
    {
        throw new GatewayApiException($message, $code, $meta);
    }
}
