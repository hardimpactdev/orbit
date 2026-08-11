<?php

declare(strict_types=1);

namespace App\Services\Nodes;

final readonly class NodeCreationInput
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        private array $arguments,
    ) {}

    public function argument(string $name): mixed
    {
        return $this->arguments[$name] ?? null;
    }

    public function option(string $name): mixed
    {
        return $this->arguments["--{$name}"] ?? null;
    }

    public function stringArgument(string $name): ?string
    {
        if (! is_string($this->arguments[$name] ?? null) || $this->arguments[$name] === '') {
            return null;
        }

        return $this->arguments[$name];
    }

    public function stringOption(string $name): ?string
    {
        $key = "--{$name}";

        if (! is_string($this->arguments[$key] ?? null) || $this->arguments[$key] === '') {
            return null;
        }

        return $this->arguments[$key];
    }

    /**
     * @return list<string>
     */
    public function arrayOption(string $name): array
    {
        $key = "--{$name}";

        if (is_string($this->arguments[$key] ?? null) && $this->arguments[$key] !== '') {
            return [$this->arguments[$key]];
        }

        if (! is_array($this->arguments[$key] ?? null)) {
            return [];
        }

        $strings = array_values(array_filter(
            $this->arguments[$key],
            fn (mixed $item): bool => is_string($item) && $item !== '',
        ));

        /** @var list<string> $strings */

        return $strings;
    }

    public function optionWasSupplied(string $name): bool
    {
        return array_key_exists("--{$name}", $this->arguments);
    }
}
