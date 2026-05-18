<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ReaderAddressRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array ACTION_SECTIONS = [
        'usage',
        'examples',
        'example',
        'what happens',
        'output',
        'recovery',
        'getting started',
        'when to use',
    ];

    /**
     * @var list<string>
     */
    private const array IMPERATIVE_OPENERS = [
        'Run', 'Use', 'Pass', 'Add', 'Check', 'Verify', 'Configure',
        'Provide', 'Confirm', 'Pick', 'Choose', 'Set', 'Open',
        'Edit', 'Try', 'Install', 'Restart', 'Stop', 'Start',
        'Apply', 'Remove', 'Inspect', 'Review', 'Read', 'See',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'prose';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->commandPageFiles() as $file) {
            $contents = file_get_contents($file) ?: '';

            foreach ($this->actionSections($contents) as $section) {
                if ($this->hasReaderOrientation($section['body'])) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $section['line'],
                    severity: FindingSeverity::Warning,
                    rule: 'command_docs.reader_address',
                    message: sprintf(
                        'Section "%s" has no `you`/`your` and no imperative-led sentence. Address the reader directly so the section reads as guidance, not as a spec.',
                        $section['heading'],
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function commandPageFiles(): array
    {
        $files = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                if (str_contains($file, '/technical/')) {
                    continue;
                }

                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array{heading: string, body: string, line: int}>
     */
    private function actionSections(string $contents): array
    {
        $sections = [];
        $current = null;
        $inFence = false;

        foreach (explode("\n", $contents) as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                if ($current !== null) {
                    $current['body'] .= "\n".$line;
                }

                continue;
            }

            if (! $inFence && preg_match('/^(?<level>#{2,3})\s+(?<heading>.+?)\s*$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                    $current = null;
                }

                $headingLower = strtolower(trim($matches['heading']));

                if (in_array($headingLower, self::ACTION_SECTIONS, true)) {
                    $current = [
                        'heading' => trim($matches['heading']),
                        'body' => '',
                        'line' => $lineNumber,
                    ];
                }

                continue;
            }

            if ($current !== null) {
                $current['body'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return $sections;
    }

    private function hasReaderOrientation(string $body): bool
    {
        $stripped = preg_replace('/```.*?```/s', '', $body) ?? $body;
        $proseOnly = preg_replace('/^\s*\|.*$/m', '', $stripped) ?? $stripped;

        if (str_word_count(strip_tags($proseOnly)) < 12) {
            return true;
        }

        if (preg_match('/\b(?:you|your)\b/i', $stripped) === 1) {
            return true;
        }

        return array_any(self::IMPERATIVE_OPENERS, fn ($verb) => preg_match('/(?:^|\n|\.\s)'.preg_quote((string) $verb, '/').'\b/', $stripped) === 1);
    }
}
