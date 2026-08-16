<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ReadCommandNoLiveProbeRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array STALE_LIVE_OUTPUT_PATTERNS = [
        '/"checks"\s*:/' => 'Read commands must not document a checks JSON field unless live inspection is explicit.',
        '/\bchecks\[\]/i' => 'Read commands must not document checks[] unless live inspection is explicit.',
        '/\bnode\.reachable\b/i' => 'Read commands must not document node.reachable unless live inspection is explicit.',
        '/\binstance\.status\b/i' => 'Read commands must not document instance.status unless live inspection is explicit.',
        '/render(?:s)? the complete progress tree/i' => 'Read-only commands must not render a progress tree unless live inspection is explicit.',
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

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = $this->docs->commandName($commandDirectory);
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! $this->docs->isFile($canonicalFile)) {
                    continue;
                }

                if (! $this->isBaseReadOnly($this->docs->contents($canonicalFile))) {
                    continue;
                }

                foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                    array_push($findings, ...$this->checkReadContractFile($file));
                }
            }
        }

        return $findings;
    }

    private function isBaseReadOnly(string $contents): bool
    {
        if (preg_match('/^\*\*Effects:\*\*\s*(?<effects>.+)$/m', $contents, $matches) !== 1) {
            return false;
        }

        $effects = strtolower($matches['effects']);

        if (! str_contains($effects, 'read')) {
            return false;
        }

        return array_all(
            ['write', 'destructive', 'stream', 'local-only'],
            fn (string $mutatingEffect): bool => ! str_contains($effects, $mutatingEffect),
        );
    }

    /**
     * @return list<Finding>
     */
    private function checkReadContractFile(string $file): array
    {
        $contents = $this->docs->contents($file);
        $findings = [];

        foreach (self::STALE_LIVE_OUTPUT_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $findings[] = $this->finding($file, $message);
        }

        return $findings;
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.read_command_no_live_probe',
            message: $message,
        );
    }
}
