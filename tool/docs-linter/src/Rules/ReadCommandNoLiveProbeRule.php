<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class ReadCommandNoLiveProbeRule implements CommandDocsLintRule
{
    /**
     * @var array<string, string>
     */
    private const array STALE_LIVE_OUTPUT_PATTERNS = [
        '/"checks"\s*:/' => 'Read commands must not document a checks JSON field unless live inspection is explicit.',
        '/\bchecks\[\]/i' => 'Read commands must not document checks[] unless live inspection is explicit.',
        '/\bnode\.reachable\b/i' => 'Read commands must not document node.reachable unless live inspection is explicit.',
        '/\bapp\.status\b/i' => 'Read commands must not document app.status unless live inspection is explicit.',
        '/render(?:s)? the complete progress tree/i' => 'Read-only commands must not render a progress tree unless live inspection is explicit.',
    ];

    public function id(): string
    {
        return 'command_docs.read_command_no_live_probe';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $canonicalContents = $context->read($canonicalFile);

                if (! $this->isBaseReadOnly($canonicalContents)) {
                    continue;
                }

                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    $findings = [
                        ...$findings,
                        ...$this->checkReadContractFile($context, $file),
                    ];
                }
            }
        }

        return $findings;
    }

    private function isBaseReadOnly(string $contents): bool
    {
        if (preg_match('/^\*\*Effects:\*\*\s*(?<effects>.+)$/m', $contents, $matches) !== 1) {
            return false;
        }

        $effects = strtolower($matches['effects']);

        if (! str_contains($effects, 'read')) {
            return false;
        }

        return array_all(['write', 'destructive', 'stream', 'local-only'], fn ($mutatingEffect) => ! str_contains($effects, (string) $mutatingEffect));
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkReadContractFile(CommandDocsLintContext $context, string $file): array
    {
        $contents = $context->read($file);
        $findings = [];

        foreach (self::STALE_LIVE_OUTPUT_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: $message,
            );
        }

        return $findings;
    }
}
