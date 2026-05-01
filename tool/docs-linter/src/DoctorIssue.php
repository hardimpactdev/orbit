<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final readonly class DoctorIssue
{
    public function __construct(
        public string $code,
        public int $line,
        public bool $hasFix = false,
        public bool $hasAdopt = false,
    ) {}

    public function withFix(): self
    {
        return new self(
            code: $this->code,
            line: $this->line,
            hasFix: true,
            hasAdopt: $this->hasAdopt,
        );
    }

    public function withAdopt(): self
    {
        return new self(
            code: $this->code,
            line: $this->line,
            hasFix: $this->hasFix,
            hasAdopt: true,
        );
    }
}
