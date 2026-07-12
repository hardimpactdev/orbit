<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RunsInternalCommands;
use Orbit\Core\Enums\InternalCommand;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class RemoteShellBackedInternalExecutor implements RunsInternalCommands
{
    public function __construct(
        private LocalExecutorCommandBuilder $commands,
        private ?RemoteShell $shell = null,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($commandName === InternalCommand::ToolRunScript->value) {
            return $this->runToolScript($node, $transportOptions);
        }

        if (in_array($commandName, ['internal:app-setup-step', 'internal:workspace-setup-step'], true)) {
            return $this->runSetupStep($node, $transportOptions);
        }

        return $this->shell()->run(
            $node,
            $this->commands->build($node, $commandName, $arguments, $commandOptions, 'test-operation-token'),
            $transportOptions,
        );
    }

    /** @param array<string, mixed> $transportOptions */
    private function runSetupStep(Node $node, array $transportOptions): RemoteShellResult
    {
        $payload = json_decode(
            (string) ($transportOptions['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $result = $this->shell()->run(
            $node,
            is_array($payload) && is_string($payload['command'] ?? null) ? $payload['command'] : '',
            [
                ...$transportOptions,
                'cwd' => is_array($payload) && is_string($payload['cwd'] ?? null) ? $payload['cwd'] : null,
                'environment' => is_array($payload) && is_array($payload['environment'] ?? null)
                    ? $payload['environment']
                    : [],
            ],
        );

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => [
                    'exit_code' => $result->exitCode,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                    'duration_ms' => $result->durationMs,
                ]],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }

    /** @param array<string, mixed> $transportOptions */
    private function runToolScript(Node $node, array $transportOptions): RemoteShellResult
    {
        $payload = json_decode(
            (string) ($transportOptions['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $result = $this->shell()->run(
            $node,
            is_array($payload) && is_string($payload['script'] ?? null) ? $payload['script'] : '',
            $transportOptions,
        );

        if (! $result->successful()) {
            return $result;
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => [
                    'exit_code' => $result->exitCode,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                    'duration_ms' => $result->durationMs,
                ]],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }

    private function shell(): RemoteShell
    {
        return $this->shell ?? app(RemoteShell::class);
    }
}
