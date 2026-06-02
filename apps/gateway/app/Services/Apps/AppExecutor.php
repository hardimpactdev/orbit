<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Services\Runtime\OrbitHostCwdResolver;
use App\Services\Support\GatewayActionResult;

final class AppExecutor
{
    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private ?string $output = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): GatewayActionResult
    {
        $this->arguments = $arguments;
        $this->output = null;

        $exitCode = $this->handle(
            app(RemoteShell::class),
            app(OrbitHostCwdResolver::class),
        );

        return GatewayActionResult::fromJsonOutput($exitCode, $this->output);
    }

    private function handle(RemoteShell $remoteShell, OrbitHostCwdResolver $cwdResolver): int
    {
        $command = $this->commandTokens();

        if ($command === []) {
            return $this->failValidation('command', 'A command to execute is required.');
        }

        $selector = $this->stringArgument('app');
        $hostCwd = $this->hostCwdFromEnv();

        if ($selector === null) {
            $context = $cwdResolver->resolve($hostCwd);

            if ($context !== null && $context->workspace === null) {
                $selector = $context->app->name;
            }
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        return $this->execIn($remoteShell, $app, $command);
    }

    /**
     * @param  list<string>  $command
     */
    private function execIn(RemoteShell $remoteShell, App $app, array $command): int
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return $this->failCommand(
                code: 'app.exec_unsupported_runtime',
                message: "App '{$app->name}' has runtime_kind={$app->runtime_kind->value}; app:exec requires runtime_kind=php.",
                meta: [
                    'app' => $app->name,
                    'runtime_kind' => $app->runtime_kind->value,
                ],
            );
        }

        $node = $app->node;

        if ($node === null) {
            return $this->failCommand(
                code: 'app.exec_source_missing',
                message: "App '{$app->name}' has no owning node; cannot run command on host.",
                meta: ['app' => $app->name],
            );
        }

        $sourcePath = rtrim((string) $app->path, '/');

        if ($sourcePath === '') {
            return $this->failCommand(
                code: 'app.exec_source_missing',
                message: "App '{$app->name}' has no source path configured.",
                meta: ['app' => $app->name],
            );
        }

        $phpVersion = $app->php_version;
        $runtimeUser = app(AppRuntimeUser::class)->forApp($app);

        $result = $remoteShell->run($node, $this->hostExecScript($sourcePath, $phpVersion, $runtimeUser, $command));

        return $this->emitSuccess([
            'app' => $app->name,
            'php_version' => $phpVersion,
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
     * Build a host-side exec script that runs the given command from the app
     * source path with the version-matched PHP binary first on PATH.
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

    private function hostCwdFromEnv(): ?string
    {
        $value = getenv('ORBIT_HOST_CWD');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveApp(string $selector): ?App
    {
        $nameMatch = App::query()
            ->with('node')
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        $domainMatch = App::query()
            ->with('node')
            ->where('domain', $selector)
            ->first();

        if ($domainMatch instanceof App) {
            return $domainMatch;
        }

        return App::query()
            ->with('node')
            ->get()
            ->first(fn (App $app): bool => $app->url() === "https://{$selector}");
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
     * Write the child command's stdout and stderr to the matching streams
     * on the parent process. When the underlying output supports a split
     * error stream (real terminals, CommandTester with
     * `capture_stderr_separately`) we use it so shell pipelines that
     * branch on stderr keep working. Tests that capture both into a
     * single buffer still see the combined output.
     */
    private function writePassthrough(string $stdout, string $stderr): void
    {
        $this->output = $stdout.$stderr;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    private function option(string $key): mixed
    {
        return $this->arguments["--{$key}"] ?? null;
    }

    private function line(string $message): void
    {
        $this->output = $message;
    }

    private function error(string $message): void
    {
        $this->output = $message;
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
