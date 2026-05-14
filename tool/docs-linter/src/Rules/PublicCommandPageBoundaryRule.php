<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;

final class PublicCommandPageBoundaryRule implements CommandDocsLintRule
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

    public function id(): string
    {
        return 'command_docs.public_page_boundaries';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $relativePath = $context->relativePath($file);

            if (! $this->isPublicCommandPage($relativePath)) {
                continue;
            }

            $contents = $context->read($file);

            array_push(
                $findings,
                ...$this->findingsForPatterns(
                    context: $context,
                    file: $file,
                    contents: $contents,
                    patterns: self::JSON_FIELD_PATTERNS,
                    message: 'Public command pages must not document JSON field paths. Move exact JSON shape to the `6.2` renderer contract and link it from the public page.',
                ),
                ...$this->findingsForPatterns(
                    context: $context,
                    file: $file,
                    contents: $contents,
                    patterns: self::JSON_ENVELOPE_PATTERNS,
                    message: 'Public command pages must not document JSON envelope details. Move exact JSON envelope behavior to the `6.2` renderer contract.',
                ),
                ...$this->findingsForPatterns(
                    context: $context,
                    file: $file,
                    contents: $contents,
                    patterns: self::EXIT_STATUS_PATTERNS,
                    message: 'Exit-status policy belongs in Failure Semantics or the shared exit status policy, not public command pages.',
                ),
                ...$this->findingsForPatterns(
                    context: $context,
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
        if (preg_match('#^docs/commands/[1-9]\d*_[a-z0-9-]+/[1-9]\d*_[a-z0-9-]+/[^/]+\.md$#', $relativePath) !== 1) {
            return false;
        }

        if (str_contains($relativePath, '/technical/')) {
            return false;
        }

        return ! in_array(basename($relativePath, '.md'), ['README', 'CHANGELOG'], true);
    }

    /**
     * @param  list<string>  $patterns
     * @return list<CommandDocsLintFinding>
     */
    private function findingsForPatterns(
        CommandDocsLintContext $context,
        string $file,
        string $contents,
        array $patterns,
        string $message,
    ): array {
        $findings = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: $message,
                    line: $index + 1,
                );

                break;
            }
        }

        return $findings;
    }
}
