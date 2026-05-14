<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\Prose\DocProfile;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;
use OrbitDocsLinter\Rules\Prose\ProseSegmenter;

/**
 * `DocumentComplexityRule` flags a 45-word bullet, but a 30-word bullet with three
 * conditions reads as a multi-clause mini-paragraph that should have been prose
 * with explicit subordination. This rule catches that pattern: a bullet that
 * combines moderate length with multiple clause separators or an embedded
 * conditional. It also flags an unbroken stretch of bullets — eight or more
 * consecutive bullets without intervening prose or a subheading nearly always
 * deserves splitting into subsections.
 */
final class BulletComplexityRule implements CommandDocsLintRule
{
    private const MULTI_CLAUSE_WORD_THRESHOLD = 25;

    private const MULTI_CLAUSE_SEPARATOR_THRESHOLD = 2;

    private const MAX_CONSECUTIVE_BULLETS = 8;

    /**
     * @var list<string>
     */
    private const CONDITIONAL_WORDS = ['if', 'when', 'unless', 'whenever', 'while'];

    public function id(): string
    {
        return 'command_docs.bullet_complexity';
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $isTechnical = DocProfile::fromPath($context->relativePath($file)) === DocProfile::Technical;
            $contents = $context->read($file);
            $paragraphs = ProseSegmenter::segment($contents);

            array_push($findings, ...$this->bulletFindings($context, $file, $paragraphs));

            if (! $isTechnical) {
                array_push($findings, ...$this->consecutiveBulletFindings($context, $file, $paragraphs));
            }
        }

        return $findings;
    }

    /**
     * @param  list<array{kind: string, text: string, line: int}>  $paragraphs
     * @return list<CommandDocsLintFinding>
     */
    private function bulletFindings(CommandDocsLintContext $context, string $file, array $paragraphs): array
    {
        $findings = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['kind'] !== ProseSegmenter::KIND_BULLET) {
                continue;
            }

            $words = ProseSegmenter::words($paragraph['text']);
            $wordCount = count($words);
            $separatorCount = substr_count($paragraph['text'], ',') + substr_count($paragraph['text'], ';');
            $hasConditional = $this->containsConditional($paragraph['text']);

            $multiClause = $wordCount >= self::MULTI_CLAUSE_WORD_THRESHOLD
                && $separatorCount >= self::MULTI_CLAUSE_SEPARATOR_THRESHOLD;

            if (! $multiClause && ! $hasConditional) {
                continue;
            }

            if ($hasConditional && $wordCount < 15) {
                continue;
            }

            $reason = $multiClause
                ? sprintf('%d words with %d clause separators', $wordCount, $separatorCount)
                : sprintf('contains an embedded conditional in %d words', $wordCount);

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: sprintf(
                    'Bullet is multi-clause (%s). Split into separate bullets or rewrite as prose with explicit subordination.',
                    $reason,
                ),
                severity: CommandDocsLintSeverity::Warning,
                line: $paragraph['line'],
            );
        }

        return $findings;
    }

    /**
     * @param  list<array{kind: string, text: string, line: int}>  $paragraphs
     * @return list<CommandDocsLintFinding>
     */
    private function consecutiveBulletFindings(CommandDocsLintContext $context, string $file, array $paragraphs): array
    {
        $findings = [];
        $streak = 0;
        $streakLine = null;

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['kind'] !== ProseSegmenter::KIND_BULLET) {
                $streak = 0;
                $streakLine = null;

                continue;
            }

            $streak++;

            if ($streakLine === null) {
                $streakLine = $paragraph['line'];
            }

            if ($streak === self::MAX_CONSECUTIVE_BULLETS + 1) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: sprintf(
                        'More than %d consecutive bullets without intervening prose or a subheading. Break the list with subheadings or convert to a table.',
                        self::MAX_CONSECUTIVE_BULLETS,
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: $streakLine,
                );
            }
        }

        return $findings;
    }

    private function containsConditional(string $text): bool
    {
        $tokens = preg_split('/\s+/', strtolower($text)) ?: [];
        $cleaned = array_map(fn (string $token): string => trim($token, " \t.,;:()`"), $tokens);

        foreach ($cleaned as $position => $token) {
            if (! in_array($token, self::CONDITIONAL_WORDS, true)) {
                continue;
            }

            if ($position === 0) {
                continue;
            }

            return true;
        }

        return false;
    }
}
