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

final class DocumentComplexityRule implements CommandDocsLintRule
{
    /**
     * Thresholds are calibrated against Laravel 13.x docs (sentence p90 ~29,
     * paragraph p90 ~63, LIX ~50). Reader-facing prose uses the tighter band;
     * technical contracts accept denser prose because the audience already knows
     * the domain and contracts trade clarity for precision.
     */
    private const READER_FACING = [
        'max_sentence_words' => 40,
        'max_paragraph_words' => 100,
        'max_bullet_words' => 35,
        'max_lix' => 60.0,
        'max_long_sentence_words' => 25,
        'max_long_sentence_share' => 0.20,
    ];

    private const TECHNICAL = [
        'max_sentence_words' => 50,
        'max_paragraph_words' => 130,
        'max_bullet_words' => 45,
        'max_lix' => 70.0,
        'max_long_sentence_words' => 30,
        'max_long_sentence_share' => 0.30,
    ];

    public function id(): string
    {
        return 'command_docs.document_complexity';
    }

    public function group(): string
    {
        return 'complexity';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $contents = $context->read($file);
            $profile = DocProfile::fromPath($context->relativePath($file));
            $thresholds = $profile === DocProfile::Technical ? self::TECHNICAL : self::READER_FACING;

            array_push(
                $findings,
                ...$this->duplicateHeadingFindings($context, $file, $contents),
                ...$this->proseFindings($context, $file, $contents, $thresholds),
            );
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function duplicateHeadingFindings(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];
        $seen = [];

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('/^(?<level>#{1,6})\s+(?<heading>.+?)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $heading = trim($matches['heading'], " \t`");
            $key = strtolower($heading);

            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }

            $findings[] = $this->warning(
                context: $context,
                path: $file,
                line: $index + 1,
                message: "Duplicate heading label `{$heading}` in one document. Rename one heading so LLMs can reference the section unambiguously.",
            );
        }

        return $findings;
    }

    /**
     * @param  array{max_sentence_words: int, max_paragraph_words: int, max_bullet_words: int, max_lix: float, max_long_sentence_words: int, max_long_sentence_share: float}  $thresholds
     * @return list<CommandDocsLintFinding>
     */
    private function proseFindings(CommandDocsLintContext $context, string $file, string $contents, array $thresholds): array
    {
        $findings = [];
        $paragraphs = ProseSegmenter::segment($contents);
        $sentenceLengths = [];
        $wordCount = 0;
        $longWordCount = 0;

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['kind'] === ProseSegmenter::KIND_BULLET) {
                $words = ProseSegmenter::words($paragraph['text']);

                if (count($words) > $thresholds['max_bullet_words']) {
                    $findings[] = $this->warning(
                        context: $context,
                        path: $file,
                        line: $paragraph['line'],
                        message: sprintf('Bullet item has %d prose words, above threshold %d. Split condition, actor, action, and result.', count($words), $thresholds['max_bullet_words']),
                    );
                }

                continue;
            }

            $paragraphWords = ProseSegmenter::words($paragraph['text']);

            if (count($paragraphWords) > $thresholds['max_paragraph_words']) {
                $findings[] = $this->warning(
                    context: $context,
                    path: $file,
                    line: $paragraph['line'],
                    message: sprintf('Paragraph has %d prose words, above threshold %d. Split the paragraph.', count($paragraphWords), $thresholds['max_paragraph_words']),
                );
            }

            foreach (ProseSegmenter::sentences($paragraph['text']) as $sentence) {
                $words = ProseSegmenter::words($sentence);

                if ($words === []) {
                    continue;
                }

                $sentenceLengths[] = count($words);
                $wordCount += count($words);
                $longWordCount += count(array_filter($words, fn (string $word): bool => strlen($word) > 6));

                if (count($words) > $thresholds['max_sentence_words']) {
                    $findings[] = $this->warning(
                        context: $context,
                        path: $file,
                        line: $paragraph['line'],
                        message: sprintf('Sentence has %d prose words, above threshold %d. Split condition, actor, action, and result.', count($words), $thresholds['max_sentence_words']),
                    );
                }
            }
        }

        if ($wordCount === 0 || $sentenceLengths === []) {
            return $findings;
        }

        $lix = (float) (array_sum($sentenceLengths) / count($sentenceLengths)) + (($longWordCount * 100) / $wordCount);

        if ($lix > $thresholds['max_lix']) {
            $findings[] = $this->warning(
                context: $context,
                path: $file,
                line: 1,
                message: sprintf('Document LIX is %.1f, above threshold %.1f. Prefer shorter sentences and simpler prose words.', $lix, $thresholds['max_lix']),
            );
        }

        $longSentences = count(array_filter(
            $sentenceLengths,
            fn (int $length): bool => $length > $thresholds['max_long_sentence_words'],
        ));
        $longSentenceShare = $longSentences / count($sentenceLengths);

        if (count($sentenceLengths) >= 4 && $longSentenceShare > $thresholds['max_long_sentence_share']) {
            $findings[] = $this->warning(
                context: $context,
                path: $file,
                line: 1,
                message: sprintf(
                    '%.0f%% of sentences exceed %d prose words, above threshold %.0f%%. Split dense requirement prose.',
                    $longSentenceShare * 100,
                    $thresholds['max_long_sentence_words'],
                    $thresholds['max_long_sentence_share'] * 100,
                ),
            );
        }

        return $findings;
    }

    private function warning(CommandDocsLintContext $context, string $path, int $line, string $message): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($path),
            ruleId: $this->id(),
            message: $message,
            severity: CommandDocsLintSeverity::Warning,
            line: $line,
        );
    }
}
