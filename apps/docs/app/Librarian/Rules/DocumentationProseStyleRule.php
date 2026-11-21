<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\BlockedPhrases;
use App\Documentation\DocumentationRepository;
use App\Documentation\MarkdownProse;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;
use HardImpact\Librarian\Markdown\ProseSegmenter;

/**
 * Rejects AI-style puffery, filler phrases, and vague headings in maintained documentation outside decision records.
 */
final readonly class DocumentationProseStyleRule implements GroupedRule
{
    public const string RULE = 'orbit.docs_prose_style';

    /** @param list<string> $vagueHeadings */
    public function __construct(
        private DocumentationRepository $repository,
        private BlockedPhrases $phrases,
        private array $vagueHeadings = [],
    ) {}

    public function group(): string
    {
        return 'prose';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->repository->markdownDocuments() as $path => $contents) {
            if (str_starts_with($path, 'docs/decisions/')) {
                continue;
            }

            $lines = MarkdownProse::lines($contents);

            array_push(
                $findings,
                ...$this->phrases->scan(
                    $lines,
                    $path,
                    self::RULE,
                    'Use plain, direct language instead.',
                ),
                ...$this->emDashFindings($lines, $path),
                ...$this->vagueHeadingFindings($lines, $path),
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, string>  $lines
     * @return list<Finding>
     */
    private function emDashFindings(array $lines, string $path): array
    {
        $findings = [];

        foreach ($lines as $lineNumber => $line) {
            if (ProseSegmenter::isTableLine($line)) {
                continue;
            }

            if (! str_contains(ProseSegmenter::cleanProse($line), "\u{2014}")) {
                continue;
            }

            $findings[] = new Finding(
                path: $path,
                line: $lineNumber,
                severity: FindingSeverity::Error,
                rule: self::RULE,
                message: 'Avoid em dashes in prose. Use commas, parentheses, or separate sentences instead.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, string>  $lines
     * @return list<Finding>
     */
    private function vagueHeadingFindings(array $lines, string $path): array
    {
        if ($this->vagueHeadings === []) {
            return [];
        }

        $findings = [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/^\s*#{1,6}\s+(.+?)\s*#*$/', $line, $matches) !== 1) {
                continue;
            }

            $heading = trim($matches[1]);
            $normalized = strtolower($heading);

            if (! in_array($normalized, $this->vagueHeadings, true)) {
                continue;
            }

            $findings[] = new Finding(
                path: $path,
                line: $lineNumber,
                severity: FindingSeverity::Error,
                rule: self::RULE,
                message: "Vague heading `{$heading}`. Replace it with a specific description of the section.",
            );
        }

        return $findings;
    }
}
