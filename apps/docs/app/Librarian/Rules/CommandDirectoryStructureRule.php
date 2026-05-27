<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class CommandDirectoryStructureRule implements GroupedRule
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

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
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
        $findings = [];
        $commandName = $this->docs->commandName($commandDirectory);
        $technicalDirectory = "{$commandDirectory}/technical";

        foreach ($this->requiredPaths($commandDirectory, $commandName) as $path => $message) {
            if (file_exists($path)) {
                continue;
            }

            $findings[] = $this->finding($this->docs->relativePath($commandDirectory), $message);
        }

        if (! is_dir($technicalDirectory)) {
            return $findings;
        }

        $seenSlots = [];

        foreach (scandir($technicalDirectory) ?: [] as $entry) {
            $path = "{$technicalDirectory}/{$entry}";

            if (is_dir($path) && $entry !== '.' && $entry !== '..') {
                $findings[] = $this->finding(
                    $this->docs->relativePath($path),
                    'Technical directories must be flat; move companion files into the technical root.',
                );

                continue;
            }

            if (! is_file($path) || ! str_ends_with($entry, '.md')) {
                continue;
            }

            $pattern = '/^(?<slot>[1-9]\d*(?:\.[1-9]\d*)?)_'.preg_quote($commandName, '/').'(?:_[a-z0-9]+(?:-[a-z0-9]+)*)*\.md$/';

            if (preg_match($pattern, $entry, $matches) !== 1) {
                $findings[] = $this->finding(
                    $this->docs->relativePath($path),
                    "Technical filenames must use a unique slot prefix and the command name, such as 1_{$commandName}.md or 6.1_{$commandName}_output-render_human.md.",
                );

                continue;
            }

            $slot = (string) $matches['slot'];

            if (isset($seenSlots[$slot])) {
                $findings[] = $this->finding(
                    $this->docs->relativePath($path),
                    "Technical slot {$slot} is already used by {$seenSlots[$slot]}.",
                );

                continue;
            }

            $seenSlots[$slot] = $entry;
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function requiredPaths(string $commandDirectory, string $commandName): array
    {
        return [
            "{$commandDirectory}/{$commandName}.md" => "Command directories must contain {$commandName}.md.",
            "{$commandDirectory}/technical" => 'Command directories must contain a technical directory.',
            "{$commandDirectory}/technical/1_{$commandName}.md" => "Technical directories must contain 1_{$commandName}.md.",
            "{$commandDirectory}/technical/6.1_{$commandName}_output-render_human.md" => "Technical directories must contain 6.1_{$commandName}_output-render_human.md.",
            "{$commandDirectory}/technical/6.2_{$commandName}_output-render_json.md" => "Technical directories must contain 6.2_{$commandName}_output-render_json.md.",
        ];
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $path,
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.command_directory_structure',
            message: $message,
        );
    }
}
