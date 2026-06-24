<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class JsonRendererEnvelopeRule implements GroupedRule
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

        foreach ($this->jsonRendererFiles() as $file) {
            $contents = file_get_contents($file) ?: '';
            $section = $this->section($contents, 'Envelope');

            if ($section === '') {
                $findings[] = $this->finding(
                    $file,
                    'JSON renderer files must include "## Envelope" with a link to the shared JSON Envelope contract.',
                );
            } elseif (! str_contains($section, 'README.md#json-envelope')) {
                $findings[] = $this->finding(
                    $file,
                    'JSON renderer Envelope sections must link the shared JSON Envelope contract.',
                );
            } elseif ($this->repeatsSharedEnvelopeProse($section)) {
                $findings[] = $this->finding(
                    $file,
                    'JSON renderer Envelope sections must not repeat the generic shared success/error envelope prose.',
                );
            }

            if (! str_contains($contents, 'success') || ! str_contains($contents, 'error')) {
                $findings[] = $this->finding(
                    $file,
                    'JSON renderer files must document the top-level success and error envelopes.',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function jsonRendererFiles(): array
    {
        $files = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                    if (preg_match('/^6\.2_.*_output-render_json\.md$/', basename($file)) === 1) {
                        $files[] = $file;
                    }
                }
            }
        }

        sort($files);

        return $files;
    }

    private function section(string $contents, string $heading): string
    {
        if (
            preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1
        ) {
            return $matches['section'];
        }

        return '';
    }

    private function repeatsSharedEnvelopeProse(string $section): bool
    {
        return (
            str_contains($section, 'All JSON responses use the standard command envelope')
            || str_contains($section, 'JSON output uses exactly one top-level envelope')
            || str_contains($section, 'Top-level `success` and `error` are mutually exclusive')
        );
    }

    private function finding(string $file, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($file),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.json_envelope',
            message: $message,
        );
    }
}
