<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\CommandDocsRegistry;
use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class SignatureOptionConsistencyRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private CommandDocsRegistry $registry,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];
        $sharedOptions = $this->registry->sharedOptions();

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                array_push($findings, ...$this->checkCommandDirectory($commandDirectory, $sharedOptions));
            }
        }

        return $findings;
    }

    /**
     * @param  array{contexts?: array<string, array{markers?: list<string>}>, options?: array<string, array{allowed_contexts?: list<string>}>}  $sharedOptions
     * @return list<Finding>
     */
    private function checkCommandDirectory(string $commandDirectory, array $sharedOptions): array
    {
        $commandName = $this->docs->commandName($commandDirectory);
        $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

        if (! is_file($canonicalFile)) {
            return [];
        }

        $findings = [];
        $allowedOptions = $this->signatureOptions(file_get_contents($canonicalFile) ?: '');

        foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
            if ($file === $canonicalFile) {
                continue;
            }

            array_push($findings, ...$this->checkCompanionFile($file, $allowedOptions, $sharedOptions));
        }

        return $findings;
    }

    /**
     * @param  list<string>  $allowedOptions
     * @param  array{contexts?: array<string, array{markers?: list<string>}>, options?: array<string, array{allowed_contexts?: list<string>}>}  $sharedOptions
     * @return list<Finding>
     */
    private function checkCompanionFile(string $file, array $allowedOptions, array $sharedOptions): array
    {
        $findings = [];
        $reportedOptions = [];

        foreach ($this->mentionedOptionsByLine(file_get_contents($file) ?: '') as $mention) {
            $option = $mention['option'];

            if (in_array($option, $allowedOptions, true) || isset($reportedOptions[$option])) {
                continue;
            }

            $referenceContexts = $this->referenceContexts($mention['line'], $sharedOptions['contexts'] ?? []);
            $reportedOptions[$option] = true;

            if ($referenceContexts !== []) {
                if ($this->isRegisteredSharedOption($option, $referenceContexts, $sharedOptions['options'] ?? [])) {
                    continue;
                }

                $findings[] = $this->finding(
                    $file,
                    "Technical companion file mentions {$option} in a foreign command reference, but {$option} is not registered as a shared option for this reference context.",
                    $mention['lineNumber'],
                );

                continue;
            }

            $findings[] = $this->finding(
                $file,
                "Technical companion file mentions {$option}, but {$option} is not in the canonical command signature.",
                $mention['lineNumber'],
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function signatureOptions(string $contents): array
    {
        $options = $this->mentionedOptions($this->signatureSection($contents));
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

    private function finding(string $path, string $message, int $line): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.signature_option_consistency',
            message: $message,
        );
    }
}
