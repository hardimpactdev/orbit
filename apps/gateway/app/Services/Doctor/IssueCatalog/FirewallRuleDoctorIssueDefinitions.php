<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit firewall_rule family Doctor issue classifications. */
final class FirewallRuleDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::invalid('firewall_rule.baseline_conflict', 'firewall_rule'),
            self::invalid('firewall_rule.node_invalid', 'firewall_rule'),
            self::invalid('firewall_rule.record_incomplete', 'firewall_rule'),
            self::blocked('firewall_rule.remote_shell_probe_failed', 'firewall_rule'),
            self::invalid('firewall_rule.rule_extra', 'firewall_rule', adoptable: true),
            self::genuine(
                'firewall_rule.rule_mismatch',
                'firewall_rule',
                'restore_firewall_rule_rule_mismatch',
            ),
            self::genuine(
                'firewall_rule.rule_missing',
                'firewall_rule',
                'restore_firewall_rule_rule_missing',
            ),
        ];
    }
}
