<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use LogicException;

/** Closed family-local name/class registrations, never class names from an API request. */
final readonly class OperationGatewayCommands
{
    /** @param array<string, class-string<OperationGatewayCommand>>|null $definitions */
    public function __construct(private ?array $definitions = null) {}

    public function get(string $name): OperationGatewayCommand
    {
        $commands = $this->definitions();
        if (! isset($commands[$name])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Gateway command is not registered.', 422);
        }
        $command = app($commands[$name]);
        if (! $command instanceof OperationGatewayCommand) {
            throw new LogicException('A Gateway command must implement OperationGatewayCommand.');
        }

        return $command;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->definitions());
    }

    /** @return array<string, class-string<OperationGatewayCommand>> */
    private function definitions(): array
    {
        $commands = $this->definitions ?? [];
        foreach ($this->definitions === null ? (glob(__DIR__.'/../*/operation-commands.php') ?: []) : [] as $file) {
            foreach (require $file as $key => $class) {
                if (isset($commands[$key])) {
                    throw new LogicException("Duplicate Gateway Operation command [{$key}].");
                }
                $commands[$key] = $class;
            }
        }

        return $commands;
    }
}
