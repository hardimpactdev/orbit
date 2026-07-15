<?php

declare(strict_types=1);

namespace App\Librarian;

final class DoctorIssuePredicateParser
{
    /**
     * @return list<array{text: string, line: int}>
     */
    public function parse(string $contents): array
    {
        $predicates = [];

        foreach ($this->issueCodeSections($contents) as $section) {
            array_push($predicates, ...$this->predicates($contents, $section));
        }

        return $predicates;
    }

    /**
     * @return list<array{body: string, offset: int}>
     */
    private function issueCodeSections(string $contents): array
    {
        $matches = [];
        preg_match_all(
            '/^## .*Issue Codes\s*$(?<body>.*?)(?=^## |\z)/ms',
            $contents,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        return array_values(array_map(
            static fn (array $match): array => [
                'body' => (string) ($match['body'][0] ?? ''),
                'offset' => (int) ($match['body'][1] ?? 0),
            ],
            $matches,
        ));
    }

    /**
     * @param  array{body: string, offset: int}  $section
     * @return list<array{text: string, line: int}>
     */
    private function predicates(string $contents, array $section): array
    {
        $matches = [];
        preg_match_all(
            '/^\|\s*`[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+`\s*\|(?<predicate>.*?)\|\s*$/m',
            $section['body'],
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        return array_values(array_map(
            fn (array $match): array => [
                'text' => (string) ($match['predicate'][0] ?? ''),
                'line' => $this->lineForOffset(
                    $contents,
                    $section['offset'] + (int) ($match[0][1] ?? 0),
                ),
            ],
            $matches,
        ));
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return (
            substr_count(
                haystack: substr(string: $contents, offset: 0, length: $offset),
                needle: "\n",
            ) + 1
        );
    }
}
