<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final readonly class JsonExample
{
    public function __construct(
        public string $path,
        public int $blockIndex,
        public int $line,
        public string $raw,
        public mixed $decoded,
        public ?string $parseError,
    ) {}

    public function isValidArray(): bool
    {
        return $this->parseError === null && is_array($this->decoded);
    }
}
