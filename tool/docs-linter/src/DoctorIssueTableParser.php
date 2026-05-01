<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final class DoctorIssueTableParser
{
    /**
     * @return array<string, DoctorIssue>
     */
    public function parse(string $contents): array
    {
        $issues = [];

        foreach ($this->sectionBodies($contents, 'Issue Codes') as $section) {
            foreach ($this->codes($contents, $section) as $code => $line) {
                $issues[$code] ??= new DoctorIssue(code: $code, line: $line);
            }
        }

        foreach ($this->sectionBodies($contents, 'Fix Map') as $section) {
            foreach ($this->codes($contents, $section) as $code => $line) {
                $issues[$code] = ($issues[$code] ?? new DoctorIssue(code: $code, line: $line))->withFix();
            }
        }

        foreach ($this->sectionBodies($contents, 'Adopt Map') as $section) {
            foreach ($this->codes($contents, $section) as $code => $line) {
                $issues[$code] = ($issues[$code] ?? new DoctorIssue(code: $code, line: $line))->withAdopt();
            }
        }

        ksort($issues);

        return $issues;
    }

    /**
     * @return list<array{body: string, offset: int}>
     */
    private function sectionBodies(string $contents, string $suffix): array
    {
        preg_match_all(
            '/^## (?<heading>.*'.preg_quote($suffix, '/').')\s*$(?<body>.*?)(?=^## |\z)/ms',
            $contents,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        return array_map(
            fn (array $match): array => [
                'body' => $match['body'][0],
                'offset' => $match['body'][1],
            ],
            $matches,
        );
    }

    /**
     * @param  array{body: string, offset: int}  $section
     * @return array<string, int>
     */
    private function codes(string $contents, array $section): array
    {
        preg_match_all(
            '/^\|\s*`(?<code>[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)`\s*\|/m',
            $section['body'],
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $codes = [];

        foreach ($matches as $match) {
            $codes[$match['code'][0]] = $this->lineForOffset($contents, $section['offset'] + $match[0][1]);
        }

        return $codes;
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
