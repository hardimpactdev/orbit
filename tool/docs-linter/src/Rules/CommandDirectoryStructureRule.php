<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class CommandDirectoryStructureRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.command_directory_structure';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $technicalDirectory = "{$commandDirectory}/technical";

                foreach ($this->requiredPaths($commandDirectory, $commandName) as $path => $message) {
                    if (! file_exists($path)) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($commandDirectory),
                            ruleId: $this->id(),
                            message: $message,
                        );
                    }
                }

                if (! is_dir($technicalDirectory)) {
                    continue;
                }

                $seenSlots = [];

                foreach (scandir($technicalDirectory) ?: [] as $entry) {
                    $path = "{$technicalDirectory}/{$entry}";

                    if (is_dir($path) && $entry !== '.' && $entry !== '..') {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($path),
                            ruleId: $this->id(),
                            message: 'Technical directories must be flat; move companion files into the technical root.',
                        );

                        continue;
                    }

                    if (! is_file($path) || ! str_ends_with($entry, '.md')) {
                        continue;
                    }

                    $pattern = '/^(?<slot>[1-9]\d*(?:\.[1-9]\d*)?)_'.preg_quote((string) $commandName, '/').'(?:_[a-z0-9]+(?:-[a-z0-9]+)*)*\.md$/';

                    if (preg_match($pattern, $entry, $matches) !== 1) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($path),
                            ruleId: $this->id(),
                            message: "Technical filenames must use a unique slot prefix and the command name, such as 1_{$commandName}.md or 6.1_{$commandName}_output-render_human.md.",
                        );

                        continue;
                    }

                    $slot = $matches['slot'];

                    if (isset($seenSlots[$slot])) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($path),
                            ruleId: $this->id(),
                            message: "Technical slot {$slot} is already used by {$seenSlots[$slot]}.",
                        );

                        continue;
                    }

                    $seenSlots[$slot] = $entry;
                }
            }
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
}
