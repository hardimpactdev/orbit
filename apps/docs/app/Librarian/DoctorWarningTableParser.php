<?php

declare(strict_types=1);

namespace App\Librarian;

final class DoctorWarningTableParser
{
    /**
     * @return list<array{code: string, family: ?string, next_command: ?string, line: int}>
     */
    public function parse(string $contents): array
    {
        $warnings = [];

        foreach ($this->warningSections($contents) as $section) {
            array_push($warnings, ...$this->warningRows($contents, $section));
        }

        return $warnings;
    }

    /**
     * @return list<array{body: string, offset: int}>
     */
    private function warningSections(string $contents): array
    {
        $matches = [];
        preg_match_all(
            '/^#{2,6} [^\n]*Warning Codes[^\n]*\s*$(?<body>.*?)(?=^#{1,6} |\z)/msi',
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
     * @return list<array{code: string, family: ?string, next_command: ?string, line: int}>
     */
    private function warningRows(string $contents, array $section): array
    {
        $matches = [];
        preg_match_all(
            '/^\|\s*`(?<code>[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)`\s*\|\s*`(?<family>[a-z_]+|null)`\s*\|(?<details>.*?)\|\s*$/m',
            $section['body'],
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        return array_values(array_map(
            fn (array $match): array => $this->warningRow($contents, $section, $match),
            $matches,
        ));
    }

    /**
     * @param  array{body: string, offset: int}  $section
     * @param  array<mixed>  $match
     * @return array{code: string, family: ?string, next_command: ?string, line: int}
     */
    private function warningRow(string $contents, array $section, array $match): array
    {
        $family = (string) ($match['family'][0] ?? '');

        return [
            'code' => (string) ($match['code'][0] ?? ''),
            'family' => $family === 'null' ? null : $family,
            'next_command' => $this->documentedNextCommand((string) ($match['details'][0] ?? '')),
            'line' => $this->lineForOffset(
                $contents,
                $section['offset'] + (int) ($match[0][1] ?? 0),
            ),
        ];
    }

    private function documentedNextCommand(string $details): ?string
    {
        $matches = [];

        if (
            preg_match(
                '/`(?<command>(?:orbit\s+)?(?:doctor(?::fix)?|[a-z][a-z0-9-]*(?::[a-z0-9-]+)+)(?:\s+[^`]*)?)`/',
                $details,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return $matches['command'];
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
