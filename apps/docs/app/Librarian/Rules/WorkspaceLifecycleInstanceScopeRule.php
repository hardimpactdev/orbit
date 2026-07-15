<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class WorkspaceLifecycleInstanceScopeRule implements GroupedRule
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
            if ($this->docs->familyName($familyDirectory) !== 'workspace') {
                continue;
            }

            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                array_push($findings, ...$this->checkCommandDirectory($commandDirectory));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkCommandDirectory(string $commandDirectory): array
    {
        $commandSlug = $this->docs->commandName($commandDirectory);

        if (preg_match('/^workspace-(?:setup|teardown)-step-(?:add|list|remove)$/', $commandSlug) !== 1) {
            return [];
        }

        $files = array_values(array_filter(
            $this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false),
            static fn (string $file): bool => preg_match('/^(?:1_|5\.[12]_|6\.[12]_)/', basename($file)) === 1,
        ));
        $publicFile = "{$commandDirectory}/{$commandSlug}.md";

        if (is_file($publicFile)) {
            $files[] = $publicFile;
        }

        $findings = [];

        foreach ($files as $file) {
            array_push($findings, ...$this->checkCompanion($file));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkCompanion(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $findings = [];
        $matches = [];

        if (
            preg_match(
                '/--app=(?:<app>|\[app\]|[a-z][a-z0-9-]*)(?![a-z0-9.-])/i',
                $contents,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $findings[] = $this->finding(
                file: $file,
                line: $this->lineForOffset($contents, (int) $matches[0][1]),
                message: 'Instance-required workspace lifecycle contracts must not advertise a bare logical-app selector; use an app-instance selector such as `--app=<app.instance>`.',
            );
        }

        $matches = [];

        if (preg_match('/\bparent[ -]app\b/i', $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $findings[] = $this->finding(
                file: $file,
                line: $this->lineForOffset($contents, (int) $matches[0][1]),
                message: 'Instance-required workspace lifecycle contracts must describe concrete app-instance ownership, not parent-app ownership.',
            );
        }

        return $findings;
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return (
            substr_count(
                haystack: substr(string: $contents, offset: 0, length: $offset),
                needle: "\n",
            ) + 1
        );
    }

    private function finding(string $file, int $line, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($file),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.workspace_lifecycle_instance_scope',
            message: $message,
        );
    }
}
