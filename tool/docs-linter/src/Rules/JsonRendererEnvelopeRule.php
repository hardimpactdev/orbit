<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class JsonRendererEnvelopeRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.json_envelope';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($this->jsonRendererFiles($context) as $file) {
            $contents = $context->read($file);

            $section = $this->section($contents, 'Envelope');

            if ($section === '') {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: 'JSON renderer files must include "## Envelope" with a link to the shared JSON Envelope contract.',
                );
            } elseif (! str_contains($section, 'README.md#json-envelope')) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: 'JSON renderer Envelope sections must link the shared JSON Envelope contract.',
                );
            } elseif ($this->repeatsSharedEnvelopeProse($section)) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: 'JSON renderer Envelope sections must not repeat the generic shared success/error envelope prose.',
                );
            }

            if (! str_contains($contents, 'success') || ! str_contains($contents, 'error')) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: 'JSON renderer files must document the top-level success and error envelopes.',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function jsonRendererFiles(CommandDocsLintContext $context): array
    {
        $files = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    if (preg_match('/^6\.2_.*_output-render_json\.md$/', basename($file)) === 1) {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function repeatsSharedEnvelopeProse(string $section): bool
    {
        return str_contains($section, 'All JSON responses use the standard command envelope')
            || str_contains($section, 'JSON output uses exactly one top-level envelope')
            || str_contains($section, 'Top-level `success` and `error` are mutually exclusive');
    }
}
