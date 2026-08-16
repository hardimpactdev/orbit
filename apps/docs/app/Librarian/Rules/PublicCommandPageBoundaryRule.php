<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class PublicCommandPageBoundaryRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array JSON_FIELD_PATTERNS = [
        '/\b(?:success|error)\.(?:data|meta|code|message)\b/i',
    ];

    /**
     * @var list<string>
     */
    private const array JSON_ENVELOPE_PATTERNS = [
        '/\b(?:success|error|structured|standard|shared)\s+(?:JSON\s+)?(?:command\s+)?envelope\b/i',
        '/\bsingle\s+top-level\s+`?success`?\s+or\s+`?error`?\b/i',
    ];

    /**
     * @var list<string>
     */
    private const array EXIT_STATUS_PATTERNS = [
        '/\bexit(?:s|ed|ing)?\s+`?(?:0|1|2|3|4|5|77)`?\b/i',
        '/\bexit(?:s|ed|ing)?\s+(?:zero|non-zero)\b/i',
        '/\bexit\s+(?:code|status)\b/i',
    ];

    /**
     * @var list<string>
     */
    private const array RENDERER_PRIMITIVE_PATTERNS = [
        '/\bprogress tree\b/i',
        '/\bhuman renderer\b/i',
        '/\bjson renderer\b(?!\s+contract\b)/i',
        '/^\s*(?:┌|└|○|●|◉|│)/u',
    ];

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

        foreach ($this->docs->markdownFiles($this->docs->commandsRoot()) as $file) {
            $relativePath = $this->docs->relativePath($file);

            if (! $this->isPublicCommandPage($relativePath)) {
                continue;
            }

            $contents = $this->docs->contents($file);

            array_push(
                $findings,
                ...$this->findingsForPatterns(
                    file: $file,
                    contents: $contents,
                    patterns: self::JSON_FIELD_PATTERNS,
                    message: 'Public command pages must not document JSON field paths. Move exact JSON shape to the `6.2` renderer contract and link it from the public page.',
                ),
                ...$this->findingsForPatterns(
                    file: $file,
                    contents: $contents,
                    patterns: self::JSON_ENVELOPE_PATTERNS,
                    message: 'Public command pages must not document JSON envelope details. Move exact JSON envelope behavior to the `6.2` renderer contract.',
                ),
                ...$this->findingsForPatterns(
                    file: $file,
                    contents: $contents,
                    patterns: self::EXIT_STATUS_PATTERNS,
                    message: 'Exit-status policy belongs in Failure Semantics or the shared exit status policy, not public command pages.',
                ),
                ...$this->findingsForPatterns(
                    file: $file,
                    contents: $contents,
                    patterns: self::RENDERER_PRIMITIVE_PATTERNS,
                    message: 'Public command pages must not name renderer primitives. Describe operator-visible output at a high level and move exact human rendering to the `6.1` renderer contract.',
                ),
            );
        }

        return $findings;
    }

    private function isPublicCommandPage(string $relativePath): bool
    {
        if (preg_match('#^docs/domains/[1-9]\d*_[a-z0-9-]+/[1-9]\d*_[a-z0-9-]+/[^/]+\.md$#', $relativePath) !== 1) {
            return false;
        }

        if (str_contains($relativePath, '/technical/')) {
            return false;
        }

        return ! in_array(basename($relativePath, '.md'), ['README', 'CHANGELOG'], true);
    }

    /**
     * @param  list<string>  $patterns
     * @return list<Finding>
     */
    private function findingsForPatterns(string $file, string $contents, array $patterns, string $message): array
    {
        $findings = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $index + 1,
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.public_page_boundaries',
                    message: $message,
                );

                break;
            }
        }

        return $findings;
    }
}
