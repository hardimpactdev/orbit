<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CliCommand
{
    /**
     * @param  list<string>  $arguments
     * @param  list<string>  $options
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public array $options = [],
    ) {}

    public function slug(): string
    {
        return str_replace([':', ' '], '-', strtolower($this->name));
    }

    public function familyToken(): string
    {
        $name = strtolower($this->name);
        $parts = preg_split('/[: ]/', $name) ?: [$name];

        return $parts[0];
    }
}
