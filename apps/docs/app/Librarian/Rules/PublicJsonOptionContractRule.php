<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class PublicJsonOptionContractRule implements GroupedRule
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

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = $this->docs->commandName($commandDirectory);
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";
                $publicFile = "{$commandDirectory}/{$commandName}.md";

                if (! $this->docs->isFile($canonicalFile) || ! $this->docs->isFile($publicFile)) {
                    continue;
                }

                if (! $this->signatureHasJsonOption($this->docs->contents($canonicalFile))) {
                    continue;
                }

                if (str_contains($this->docs->contents($publicFile), '--json')) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $this->docs->relativePath($publicFile),
                    line: null,
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.public_json_option_contract',
                    message: 'Public command page must mention `--json` when the canonical signature accepts it.',
                );
            }
        }

        return $findings;
    }

    private function signatureHasJsonOption(string $contents): bool
    {
        return str_contains($this->section($contents, 'Signature'), '--json');
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
}
