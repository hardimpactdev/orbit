<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\TaskWorkspaceMetadata;
use LogicException;
use RuntimeException;

/** Runs metadata in isolated Python processes; native turn commands remain native. */
final class TurnMetadataSshExecutor implements SshExecutor
{
    /** @var array<string, string|false> */
    public array $environment = [];

    private static ?PythonPrograms $python = null;

    public static function prepare(): void
    {
        self::$python = new PythonPrograms;
    }

    public static function forget(): void
    {
        self::$python = null;
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($command->output !== null || $command->cancelled !== null || $command->protectedInput !== null) {
            throw new LogicException('Streaming, cancellation and protected-input proofs require the native transport.');
        }
        HostPathGuardedProcessRunner::assertSafe($command->arguments, $command->input);
        $prefix = "checkout=\$1\n".TaskWorkspaceMetadata::bashPreamble();
        $shell = $command->input ?? '';
        if (count($command->arguments) !== 4
            || array_slice($command->arguments, 0, 3) !== ['bash', '-seu', '--']
            || ! str_starts_with($shell, $prefix)
            || preg_match("~\Aprintf '%s' '([A-Za-z0-9+/=]+)' \\| base64 -d \\| workspace_metadata '(turn|read|legacy|clear)'\n\z~D", substr($shell, strlen($prefix)), $matches) !== 1) {
            throw new LogicException('Only exact generated turn metadata commands belong to this fixture.');
        }
        $input = base64_decode($matches[1], true);
        if ($input === false || base64_encode($input) !== $matches[1]) {
            throw new LogicException('The turn metadata fixture requires canonical base64 input.');
        }
        $python = self::$python ?? throw new LogicException('Prepare the metadata fixture in beforeAll.');

        return $python->runCommand(
            self::source(resource_path('tasks/metadata')),
            [$command->arguments[3], $matches[2]],
            $input,
            [...$this->environment, 'COMPOSER_AUTH' => false],
            $command->timeout,
        );
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Could not read the metadata fixture source.');
        }

        return $source;
    }
}
