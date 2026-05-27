<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class NoPerCommandAuthorizationSectionRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array BANNED_HEADINGS = [
        '## Authorization By Caller Role',
        '## Authorization',
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

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $contents = file_get_contents($canonicalFile) ?: '';

                foreach (self::BANNED_HEADINGS as $heading) {
                    if (preg_match('/^'.preg_quote($heading, '/').'\s*$/m', $contents) !== 1) {
                        continue;
                    }

                    $findings[] = new Finding(
                        path: $this->docs->relativePath($canonicalFile),
                        line: null,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.no_per_command_authorization_section',
                        message: "Canonical technical contracts must not include a dedicated '{$heading}' section. Authorization is gateway-owned and applies generically to every API call; document role-specific rejections in Prerequisites and Failure Semantics.",
                    );
                }
            }
        }

        return $findings;
    }
}
