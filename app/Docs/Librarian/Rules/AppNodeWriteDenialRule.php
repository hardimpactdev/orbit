<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class AppNodeWriteDenialRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            if (basename($familyDirectory) !== '5_app') {
                continue;
            }

            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = $this->docs->commandName($commandDirectory);
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $canonicalContents = file_get_contents($canonicalFile) ?: '';

                if (! $this->hasWriteEffect($canonicalContents)) {
                    continue;
                }

                $contractContents = $this->combinedContractContents($commandDirectory);

                if (! str_contains($contractContents, 'caller_role_not_allowed')) {
                    $findings[] = $this->finding($canonicalFile, 'App write commands must document app-node denial with error.code=caller_role_not_allowed.');
                }

                if (! $this->documentsAppNodeDenial($contractContents)) {
                    $findings[] = $this->finding($canonicalFile, 'App write commands must explicitly state that app-node callers are denied.');
                }

                if (! $this->documentsPreSideEffectDenial($contractContents)) {
                    $findings[] = $this->finding($canonicalFile, 'App-node denial must be documented as happening before prompts or side effects.');
                }
            }
        }

        return $findings;
    }

    private function hasWriteEffect(string $contents): bool
    {
        if (preg_match('/^\*\*Effects:\*\*\s*(?<effects>.+)$/m', $contents, $matches) !== 1) {
            return false;
        }

        $effects = strtolower((string) $matches['effects']);

        return str_contains($effects, 'write')
            || str_contains($effects, 'destructive')
            || str_contains($effects, 'stream');
    }

    private function combinedContractContents(string $commandDirectory): string
    {
        $contents = [];

        foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
            $contents[] = file_get_contents($file) ?: '';
        }

        return strtolower(implode("\n", $contents));
    }

    private function documentsAppNodeDenial(string $contents): bool
    {
        return str_contains($contents, 'app-node callers are denied')
            || str_contains($contents, 'app callers are denied')
            || str_contains($contents, '| `app` | denied')
            || str_contains($contents, 'deny `app` nodes')
            || str_contains($contents, 'caller role is `app`');
    }

    private function documentsPreSideEffectDenial(string $contents): bool
    {
        return (str_contains($contents, 'before prompts') && str_contains($contents, 'side effects'))
            || str_contains($contents, 'before prompts or side effects')
            || str_contains($contents, 'before prompts, forwarding')
            || str_contains($contents, 'before side effects');
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.app_node_write_denial',
            message: $message,
        );
    }
}
