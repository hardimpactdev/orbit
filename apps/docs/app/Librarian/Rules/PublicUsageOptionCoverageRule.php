<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use App\Librarian\PublicCommandOptionParser;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class PublicUsageOptionCoverageRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private PublicCommandOptionParser $optionParser,
    ) {}

    public function group(): string
    {
        return 'contracts';
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
        $commandSlug = $this->docs->commandName($commandDirectory);
        $canonicalFile = "{$commandDirectory}/technical/1_{$commandSlug}.md";
        $publicFile = "{$commandDirectory}/{$commandSlug}.md";

        if (! $this->docs->isFile($canonicalFile) || ! $this->docs->isFile($publicFile)) {
            return [];
        }

        $canonicalContents = $this->docs->contents($canonicalFile);
        $usageSignature = $this->optionParser->normativeUsageSignature($this->docs->contents($publicFile));

        if ($usageSignature === null) {
            return [];
        }

        $canonicalOptions = $this->optionParser->options(
            $this->optionParser->section($canonicalContents, 'Signature'),
        );
        $usageOptions = $this->optionParser->options($usageSignature);
        $findings = [];

        foreach (array_diff($canonicalOptions, $usageOptions) as $option) {
            if ($this->isDocumentedNonPublicOption($canonicalContents, $option)) {
                continue;
            }

            $findings[] = new Finding(
                path: $this->docs->relativePath($publicFile),
                line: null,
                severity: FindingSeverity::Error,
                rule: 'command_docs.public_usage_option_coverage',
                message: "Public Usage signature omits canonical public option `{$option}`.",
            );
        }

        return $findings;
    }

    private function isDocumentedNonPublicOption(string $contents, string $option): bool
    {
        foreach (explode("\n", $contents) as $line) {
            if (! str_contains($line, $option)) {
                continue;
            }

            if (
                preg_match(
                    '/\b(?:non-public|internal-only|renderer-only|technical-only|implementation-only)\b/i',
                    $line,
                ) === 1
            ) {
                return true;
            }

            if (preg_match('/\bgateway default\b/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
