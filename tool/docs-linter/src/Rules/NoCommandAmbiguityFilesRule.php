<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class NoCommandAmbiguityFilesRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.no_command_ambiguity_files';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        if (! is_dir($context->scanRoot)) {
            return [];
        }

        $findings = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($context->scanRoot));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($file->getFilename() !== 'ambiguity.md') {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file->getPathname()),
                ruleId: $this->id(),
                message: 'Command ambiguity tracking lives outside the repository; remove ambiguity.md and move unresolved decisions to the external tracker.',
            );
        }

        return $findings;
    }
}
