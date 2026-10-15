<?php

declare(strict_types=1);

namespace App\Console\Commands;

use InvalidArgumentException;

trait ReadsStringInput
{
    protected function stringArgument(string $name): string
    {
        $value = $this->input->getArgument($name);
        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$name} argument must be a string.");
        }

        return $value;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->input->getOption($name);
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The --{$name} option must be a string.");
        }

        return $value;
    }
}
