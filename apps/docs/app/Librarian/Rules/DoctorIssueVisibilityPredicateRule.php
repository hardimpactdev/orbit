<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CommandDocsRegistry;
use App\Librarian\DoctorIssuePredicateParser;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class DoctorIssueVisibilityPredicateRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private CommandDocsRegistry $registry,
        private DoctorIssuePredicateParser $predicateParser,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];
        $checkedPaths = [];

        foreach ($this->registry->stateFamilies() as $stateFamily) {
            $doctorPath = $stateFamily['doctor_doc'];

            if ($doctorPath === null || array_key_exists($doctorPath, $checkedPaths)) {
                continue;
            }

            $checkedPaths[$doctorPath] = true;
            $file = "{$this->docs->docsRoot()}/{$doctorPath}";

            if (! $this->docs->isFile($file)) {
                continue;
            }

            foreach ($this->predicateParser->parse($this->docs->contents($file)) as $predicate) {
                if (! $this->describesCallerVisibility($predicate['text'])) {
                    continue;
                }

                if ($this->isCallerLocalSelfPreference($predicate['text'])) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $predicate['line'],
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.doctor_issue_visibility_predicate',
                    message: 'Doctor issue predicates must describe authoritative state drift, not caller authorization or visibility.',
                );
            }
        }

        return $findings;
    }

    private function describesCallerVisibility(string $predicate): bool
    {
        return (
            preg_match(
                '/\bunauthori[sz]ed\b|\bcaller[- ]visibility\b|\b(?:not|no longer)\s+(?:caller[- ]visible|visible to (?:the )?caller)\b|\bhidden from (?:the )?caller\b|\bcaller\s+(?:cannot|can\x{2019}t|can\'t)\s+(?:see|view|access)\b/iu',
                $predicate,
            ) === 1
        );
    }

    private function isCallerLocalSelfPreference(string $predicate): bool
    {
        return (
            preg_match('/\bpreference\b/i', $predicate) === 1
            && preg_match('/\bcaller-local\b|doctor\s+--self|\bself\b/i', $predicate) === 1
        );
    }
}
