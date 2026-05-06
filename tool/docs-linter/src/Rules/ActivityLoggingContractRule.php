<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class ActivityLoggingContractRule implements CommandDocsLintRule
{
    /**
     * Commands whose canonical technical contracts must declare the
     * `## Activity Logging` section. Add commands as they backfill the
     * section so the rule grows from the allowlist toward fleet coverage.
     *
     * @var list<string>
     */
    private const array ENFORCED_COMMANDS = [
        'app-list',
        'app-new',
        'app-register',
        'app-root',
        'app-remove',
        'app-agent-ide',
        'agent-ide-message',
        'app-show',
        'activity-list',
        'activity-show',
        'gateway-add',
        'gateway-trust',
        'update',
        'update-all',
        'doctor',
        'profile',
        'dns-list',
        'dns-resolve-tld',
        'tool-list',
        'tool-show',
        'node-new',
        'node-list',
        'node-show',
        'node-grant',
        'node-default',
        'node-update',
        'node-revoke',
        'node-remove',
        'node-agent-ide',
        'workspace-list',
        'workspace-show',
        'workspace-history',
        'workspace-log',
        'workspace-setup-step-add',
        'workspace-setup-step-list',
        'workspace-setup-step-remove',
        'workspace-teardown-step-add',
        'workspace-teardown-step-list',
        'workspace-teardown-step-remove',
        'process-add',
        'process-edit',
        'process-remove',
        'process-list',
        'process-start',
        'process-stop',
        'process-restart',
        'process-logs',
        'schedule-add',
        'schedule-list',
        'schedule-show',
        'schedule-remove',
        'schedule-run',
        'schedule-logs',
        'firewall-list',
        'firewall-allow',
        'firewall-deny',
        'firewall-remove',
        'proxy-list',
        'proxy-add',
        'proxy-remove',
    ];

    /**
     * @var list<string>
     */
    private const array REQUIRED_FIELDS = [
        'Type',
        'Effect',
        'Subject',
        'Properties',
    ];

    public function id(): string
    {
        return 'command_docs.activity_logging_contract';
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
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory)) ?? '';

                if (! in_array($commandName, self::ENFORCED_COMMANDS, true)) {
                    continue;
                }

                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($file)) {
                    continue;
                }

                $contents = $context->read($file);
                $section = $this->activityLoggingSection($contents);

                if ($section === null) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: 'Canonical technical contracts must include `## Activity Logging` declaring the per-command Loggable contract.',
                    );

                    continue;
                }

                if ($this->declaresNoEmission($section)) {
                    continue;
                }

                foreach (self::REQUIRED_FIELDS as $field) {
                    if (! $this->mentionsField($section, $field)) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: "`## Activity Logging` must include a `{$field}` row, or declare the command does not emit.",
                        );
                    }
                }
            }
        }

        return $findings;
    }

    private function activityLoggingSection(string $contents): ?string
    {
        if (preg_match('/^## Activity Logging\s*$(?<section>.*?)(?=^## |\z)/ms', $contents, $matches) !== 1) {
            return null;
        }

        return $matches['section'];
    }

    private function declaresNoEmission(string $section): bool
    {
        return str_contains(strtolower($section), 'does not emit');
    }

    private function mentionsField(string $section, string $field): bool
    {
        return preg_match('/^\|\s*'.preg_quote($field, '/').'\s*\|/m', $section) === 1;
    }
}
