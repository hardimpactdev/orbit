<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class CommandRegistrySnapshot
{
    public static function capture(Kernel $kernel): array
    {
        $commands = array_map(static fn (Command $command): array => [
            'class' => $command::class,
            'aliases' => $command->getAliases(),
            'hidden' => $command->isHidden(),
            'description' => $command->getDescription(),
            'help' => $command->getHelp(),
            'arguments' => array_map(static fn (InputArgument $argument): array => [
                $argument->getName(), $argument->isRequired(), $argument->isArray(),
                $argument->getDefault(), $argument->getDescription(),
            ], $command->getDefinition()->getArguments()),
            'options' => array_map(static fn (InputOption $option): array => [
                $option->getName(), $option->getShortcut(), $option->acceptValue(),
                $option->isValueRequired(), $option->isValueOptional(), $option->isArray(),
                $option->isNegatable(), $option->getDefault(), $option->getDescription(),
            ], $command->getDefinition()->getOptions()),
        ], $kernel->all());
        ksort($commands);

        return $commands;
    }
}
