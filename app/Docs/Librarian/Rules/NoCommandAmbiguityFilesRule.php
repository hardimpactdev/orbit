<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class NoCommandAmbiguityFilesRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'structure';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->markdownFiles($this->docs->commandsRoot()) as $file) {
            if (basename($file) !== 'ambiguity.md') {
                continue;
            }

            $findings[] = new Finding(
                path: $this->docs->relativePath($file),
                line: null,
                severity: FindingSeverity::Error,
                rule: 'command_docs.no_command_ambiguity_files',
                message: 'Command ambiguity tracking lives outside the repository; remove ambiguity.md and move unresolved decisions to the external tracker.',
            );
        }

        return $findings;
    }
}
