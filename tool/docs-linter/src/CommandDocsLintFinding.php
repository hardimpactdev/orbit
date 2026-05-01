<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final readonly class CommandDocsLintFinding
{
    public function __construct(
        public string $path,
        public string $ruleId,
        public string $message,
        public CommandDocsLintSeverity $severity = CommandDocsLintSeverity::Error,
        public ?int $line = null,
    ) {}
}
