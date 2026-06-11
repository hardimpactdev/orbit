<?php

declare(strict_types=1);

namespace App\Librarian;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads the live public Orbit CLI command surface from `apps/cli/orbit
 * list --format=json` so docs rules can compare command docs against the
 * commands that actually exist.
 */
final class LiveCliSurface implements CliSurface
{
    /**
     * Framework-global options present on every command definition; they are
     * not part of a command's documented contract.
     */
    private const array GLOBAL_OPTIONS = [
        'ansi',
        'env',
        'help',
        'no-ansi',
        'no-interaction',
        'quiet',
        'silent',
        'verbose',
        'version',
    ];

    private const array VENDOR_COMMANDS = [
        '_complete',
        'completion',
        'help',
        'list',
    ];

    /** @var list<CliCommand>|null */
    private ?array $commands = null;

    /**
     * @return list<CliCommand>
     */
    public function publicCommands(): array
    {
        return $this->commands ??= $this->load();
    }

    /**
     * @return list<CliCommand>
     */
    private function load(): array
    {
        $entryPoint = $this->cliEntryPoint();
        $process = new Process([PHP_BINARY, $entryPoint, 'list', '--format=json'], dirname($entryPoint));
        $process->mustRun();

        try {
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode the Orbit CLI command surface from `list --format=json`.', previous: $exception);
        }

        $commands = [];

        foreach ((array) ($payload['commands'] ?? []) as $command) {
            if (! is_array($command) || ($command['hidden'] ?? true) === true) {
                continue;
            }

            $name = (string) ($command['name'] ?? '');

            if ($name === '' || in_array($name, self::VENDOR_COMMANDS, true)) {
                continue;
            }

            $commands[] = new CliCommand(
                name: $name,
                arguments: array_map(strval(...), array_keys((array) ($command['definition']['arguments'] ?? []))),
                options: array_values(array_filter(
                    array_map(strval(...), array_keys((array) ($command['definition']['options'] ?? []))),
                    static fn (string $option): bool => ! in_array($option, self::GLOBAL_OPTIONS, true),
                )),
            );
        }

        return $commands;
    }

    private function cliEntryPoint(): string
    {
        $path = realpath(base_path('../cli/orbit'));

        if ($path === false) {
            throw new RuntimeException('Unable to locate the Orbit CLI entry point at apps/cli/orbit.');
        }

        return $path;
    }
}
