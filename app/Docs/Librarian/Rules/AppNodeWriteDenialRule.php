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

                $permission = $this->permissionForCommand($commandName);

                if ($permission === null) {
                    continue;
                }

                $contractContents = $this->combinedContractContents($commandDirectory);

                if (str_contains($contractContents, 'caller_role_not_allowed')) {
                    $findings[] = $this->finding($canonicalFile, 'App write commands must not document caller_role_not_allowed as app write authorization.');
                }

                if (! str_contains($contractContents, "`{$permission}`")) {
                    $findings[] = $this->finding($canonicalFile, "App write commands must document the required `{$permission}` grant permission.");
                }

                if (! str_contains($contractContents, 'authorization_failed')) {
                    $findings[] = $this->finding($canonicalFile, 'App write commands must use authorization_failed for missing app write grants.');
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

    private function permissionForCommand(string $commandName): ?string
    {
        return match ($commandName) {
            'app-new' => 'app:new',
            'app-register' => 'app:register',
            'app-root' => 'app:root',
            'app-remove' => 'app:remove',
            'app-prune' => 'app:prune',
            'app-agent-ide' => 'app:agent',
            default => null,
        };
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
