<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class TransitionalSseContractRule implements GroupedRule
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
            if ($this->docs->familyName($familyDirectory) !== 'operation') {
                continue;
            }

            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($this->docs->markdownFiles($commandDirectory) as $file) {
                    array_push($findings, ...$this->checkFile($file));
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file): array
    {
        $contents = $this->docs->contents($file);
        $sections = [];
        preg_match_all(
            '/(?:\A|^## ).*?(?=^## |\z)/ms',
            $contents,
            $sections,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $findings = [];

        foreach ($sections as $sectionMatch) {
            $section = $sectionMatch[0][0] ?? '';
            $sectionOffset = (int) ($sectionMatch[0][1] ?? 0);

            $transportMatch = [];
            if (preg_match('/\bSSE\b|Last-Event-ID/i', $section, $transportMatch, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            if (preg_match('/\btransitional\b/i', $section) === 1) {
                continue;
            }

            $transportOffset = (int) ($transportMatch[0][1] ?? 0);
            $line =
                substr_count(
                    haystack: substr(
                        string: $contents,
                        offset: 0,
                        length: $sectionOffset + $transportOffset,
                    ),
                    needle: "\n",
                ) + 1;

            $findings[] = new Finding(
                path: $this->docs->relativePath($file),
                line: $line,
                severity: FindingSeverity::Error,
                rule: 'command_docs.transitional_sse_contract',
                message: 'Operation sections that mention SSE or `Last-Event-ID` must explicitly mark that transport as transitional in the same section.',
            );
        }

        return $findings;
    }
}
