<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use JsonException;
use LogicException;

/**
 * The commands of the pinned agent, read from `apps/agent/contract/commands.json`. Rust writes that file
 * from the compiled commands and a Rust test keeps it current, so the privilege, bound, and error codes
 * live in one place.
 */
final class AgentContract
{
    /** @var array<string, AgentCommandSpec>|null */
    private ?array $commands = null;

    /** @var list<string> */
    private array $commonErrors = [];

    /** @var list<string> */
    private array $notStartedErrors = [];

    /** @var list<string> */
    private array $preflightErrors = [];

    public function __construct(private readonly string $path) {}

    public function command(string $name): AgentCommandSpec
    {
        return $this->commands()[$name] ?? throw new LogicException("The agent contract has no command [{$name}].");
    }

    private const array OperatorOnly = ['swamp.issue-node-worker'];

    public function operationCommand(string $name): AgentCommandSpec
    {
        if (in_array($name, self::OperatorOnly, true)) {
            throw new LogicException('Operator-only command.');
        }

        return $this->command($name);
    }

    /** @return list<string> */
    public function operationNames(): array
    {
        return array_values(array_diff($this->names(), self::OperatorOnly));
    }

    /** @return list<string> */
    public function commonErrors(): array
    {
        $this->commands();

        return $this->commonErrors;
    }

    /** @return list<string> */
    public function notStartedErrors(): array
    {
        $this->commands();

        return $this->notStartedErrors;
    }

    /** @return list<string> */
    public function preflightErrors(): array
    {
        $this->commands();

        return $this->preflightErrors;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->commands());
    }

    /** @return array<string, AgentCommandSpec> */
    private function commands(): array
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new LogicException("The agent contract [{$this->path}] is missing.");
        }

        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException("The agent contract [{$this->path}] is not JSON.", previous: $exception);
        }

        if (! is_array($document) || ! is_array($document['commands'] ?? null)) {
            throw new LogicException("The agent contract [{$this->path}] has no commands.");
        }

        $commands = [];
        foreach ($document['commands'] as $command) {
            $spec = is_array($command) ? self::spec($command) : null;
            if ($spec === null) {
                throw new LogicException("The agent contract [{$this->path}] has an invalid command.");
            }
            if ($spec->outputMaxBytes > 0 && ($document['output_chunk_bytes'] ?? null) !== AgentOutputDecoder::ChunkBytes) {
                throw new LogicException('The agent binary chunk contract does not match the runner.');
            }
            $commands[$spec->name] = $spec;
        }

        $this->commonErrors = self::strings($document['common_errors'] ?? null) ?? [];
        $this->notStartedErrors = self::strings($document['not_started_errors'] ?? null) ?? throw new LogicException('The agent contract has no pre-start error classification.');
        $this->preflightErrors = self::strings($document['preflight_errors'] ?? null) ?? throw new LogicException('The agent contract has no preflight error classification.');

        return $this->commands = $commands;
    }

    /** @param  array<mixed>  $command */
    private static function spec(array $command): ?AgentCommandSpec
    {
        $privilege = $command['privilege'] ?? null;
        $errors = self::strings($command['errors'] ?? null);
        $linux = is_array($privilege) ? ($privilege['linux'] ?? null) : null;
        $macos = is_array($privilege) ? ($privilege['macos'] ?? null) : null;
        $outputMax = $command['output_max_bytes'] ?? 0;
        $inputMax = $command['input_max_bytes'] ?? 0;

        if (! is_string($command['name'] ?? null) || ! is_bool($command['mutates'] ?? null) || ! is_int($command['timeout_seconds'] ?? null)
            || ! is_int($inputMax) || $inputMax < 0
            || $errors === null || ! is_int($outputMax) || $outputMax < 0 || ($outputMax > 0 && $command['mutates'])
            || ($linux !== null && ! is_string($linux)) || ($macos !== null && ! is_string($macos))) {
            return null;
        }

        return new AgentCommandSpec($command['name'], $command['mutates'], $command['timeout_seconds'], $linux, $macos, $errors, $outputMax, $inputMax);
    }

    /** @return list<string>|null */
    private static function strings(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
        }

        return $value;
    }
}
