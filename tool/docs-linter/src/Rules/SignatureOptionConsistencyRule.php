<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsRegistry;

final class SignatureOptionConsistencyRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.signature_option_consistency';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];
        $sharedOptions = (new CommandDocsRegistry($context->repositoryRoot))->sharedOptions();

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $allowedOptions = $this->signatureOptions($context->read($canonicalFile));

                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    if ($file === $canonicalFile) {
                        continue;
                    }

                    $reportedOptions = [];

                    foreach ($this->mentionedOptionsByLine($context->read($file)) as $mention) {
                        $option = $mention['option'];
                        $line = $mention['line'];
                        $lineNumber = $mention['lineNumber'];

                        if (! in_array($option, $allowedOptions, true)) {
                            if (isset($reportedOptions[$option])) {
                                continue;
                            }

                            $referenceContexts = $this->referenceContexts($line, $sharedOptions['contexts'] ?? []);

                            if ($referenceContexts !== []) {
                                if ($this->isRegisteredSharedOption($option, $referenceContexts, $sharedOptions['options'] ?? [])) {
                                    continue;
                                }

                                $reportedOptions[$option] = true;

                                $findings[] = new CommandDocsLintFinding(
                                    path: $context->relativePath($file),
                                    ruleId: $this->id(),
                                    message: "Technical companion file mentions {$option} in a foreign command reference, but {$option} is not registered as a shared option for this reference context.",
                                    line: $lineNumber,
                                );

                                continue;
                            }

                            $reportedOptions[$option] = true;

                            $findings[] = new CommandDocsLintFinding(
                                path: $context->relativePath($file),
                                ruleId: $this->id(),
                                message: "Technical companion file mentions {$option}, but {$option} is not in the canonical command signature.",
                                line: $lineNumber,
                            );
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function signatureOptions(string $contents): array
    {
        $signatureSection = $this->signatureSection($contents);

        $options = $this->mentionedOptions($signatureSection);
        sort($options);

        return $options;
    }

    private function signatureSection(string $contents): string
    {
        if (preg_match('/\n## Signature\s*(?<section>.*?)(?:\n## |\z)/s', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function mentionedOptions(string $contents): array
    {
        preg_match_all('/(?<![a-zA-Z0-9])--[a-z0-9][a-z0-9-]*/', $contents, $matches);

        $options = array_values(array_unique($matches[0]));
        sort($options);

        return $options;
    }

    /**
     * @return list<array{option: string, line: string, lineNumber: int}>
     */
    private function mentionedOptionsByLine(string $contents): array
    {
        $mentions = [];

        foreach (explode("\n", $contents) as $index => $line) {
            foreach ($this->mentionedOptions($line) as $option) {
                $mentions[] = [
                    'option' => $option,
                    'line' => $line,
                    'lineNumber' => $index + 1,
                ];
            }
        }

        return $mentions;
    }

    /**
     * @param  array<string, array{markers?: list<string>}>  $contexts
     * @return list<string>
     */
    private function referenceContexts(string $line, array $contexts): array
    {
        $matched = [];

        foreach ($contexts as $context => $metadata) {
            foreach ($metadata['markers'] ?? [] as $marker) {
                if (! str_contains($line, $marker)) {
                    continue;
                }

                $matched[] = $context;

                break;
            }
        }

        return $matched;
    }

    /**
     * @param  list<string>  $referenceContexts
     * @param  array<string, array{allowed_contexts?: list<string>}>  $options
     */
    private function isRegisteredSharedOption(string $option, array $referenceContexts, array $options): bool
    {
        $allowedContexts = $options[$option]['allowed_contexts'] ?? [];

        foreach ($referenceContexts as $referenceContext) {
            if (in_array($referenceContext, $allowedContexts, true)) {
                return true;
            }
        }

        return false;
    }
}
