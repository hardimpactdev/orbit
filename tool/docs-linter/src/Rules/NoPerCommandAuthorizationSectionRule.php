<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class NoPerCommandAuthorizationSectionRule implements CommandDocsLintRule
{
    /**
     * @var list<string>
     */
    private const array BANNED_HEADINGS = [
        '## Authorization By Caller Role',
        '## Authorization',
    ];

    public function id(): string
    {
        return 'command_docs.no_per_command_authorization_section';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $contents = $context->read($canonicalFile);

                foreach (self::BANNED_HEADINGS as $heading) {
                    if (preg_match('/^'.preg_quote($heading, '/').'\s*$/m', $contents) === 1) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($canonicalFile),
                            ruleId: $this->id(),
                            message: "Canonical technical contracts must not include a dedicated '{$heading}' section. Authorization is gateway-owned and applies generically to every API call; document role-specific rejections in Prerequisites and Failure Semantics.",
                        );
                    }
                }
            }
        }

        return $findings;
    }
}
