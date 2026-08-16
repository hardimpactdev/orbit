<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class SharedFailureVocabularyRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array BANNED_CODES = [
        'auth.unauthorized_role' => 'Use authorization_failed with error.meta.missing_permission.',
        'caller_role_not_allowed' => 'Use authorization_failed with error.meta.missing_permission.',
        'ambiguous_workspace' => 'Use workspace.ambiguous_name for workspace name ambiguity.',
        'artifact_enactment_failed' => 'Use a singular product code such as node.artifact_enactment_failed.',
        'consent_required' => 'Use validation_failed with error.meta.field=force for missing or declined destructive consent.',
        'field_role_incompatible' => 'Use node.field_role_incompatible for node field/role eligibility failures.',
        'gateway_api_error' => 'Use node.gateway_api_error for gateway API response failures owned by node onboarding.',
        'gateway_node_removal_denied' => 'Use node.gateway_removal_denied for gateway node removal refusal.',
        'gateway_unreachable' => 'Use gateway_unavailable for configured gateway API reachability failures.',
        'general.consent_required' => 'Use validation_failed with error.meta.field=force for missing or declined destructive consent.',
        'grant_policy_violation' => 'Use node.grant_policy_violation for node grant policy failures.',
        'identity_unknown' => 'Use node.identity_unknown for node identity lookup failures.',
        'incompatible_node' => 'Use node.incompatible for incompatible node role or state.',
        'invalid_node_role' => 'Use node.invalid_role for invalid node role failures.',
        'local_config_write_failed' => 'Use node.local_config_write_failed for local node configuration write failures.',
        'log_not_found' => 'Use workspace.log_not_found for workspace log lookup failures.',
        'missing_argument' => 'Use validation_failed with error.meta.field for missing input.',
        'missing_input' => 'Use validation_failed with error.meta.field for missing input.',
        'node_not_found' => 'Use node.not_found for node lookup failures.',
        'node_provisioning_incomplete' => 'Use node.provisioning_incomplete for incomplete node provisioning.',
        'node.ssh_unreachable' => 'Use node.bootstrap_ssh_failed with error.meta.step=client_ssh_preflight|client_ssh_bootstrap.',
        'run_not_found' => 'Use workspace.run_not_found for workspace run lookup failures.',
        'ssh_unreachable' => 'Use node.bootstrap_ssh_failed with error.meta.step=client_ssh_preflight|client_ssh_bootstrap.',
        'unauthorized_role' => 'Use authorization_failed with error.meta.missing_permission.',
        'unsupported_adapter' => 'Use the owning product code, such as node.unsupported_adapter or instance.unsupported_adapter.',
        'unsupported_platform' => 'Use node.unsupported_platform for unsupported node platform failures.',
        'validation.missing_argument' => 'Use validation_failed with error.meta.field for missing input.',
        'validation.missing_input' => 'Use validation_failed with error.meta.field for missing input.',
        'wireguard_peer_detach_failed' => 'Use node.wireguard_peer_extra when the remaining drift is a stale WireGuard peer.',
        'workspace_not_found' => 'Use workspace.not_found for workspace lookup failures.',
        'workspace_setup.http_probe_unhealthy' => 'Use workspace.http_probe_unhealthy for workspace setup HTTP probe warnings.',
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
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                $contents = $this->docs->contents($file);

                foreach (self::BANNED_CODES as $code => $replacement) {
                    if (! $this->containsCode($contents, $code)) {
                        continue;
                    }

                    $findings[] = new Finding(
                        path: $this->docs->relativePath($file),
                        line: null,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.shared_failure_vocabulary',
                        message: "Stale failure code `{$code}` is documented. {$replacement}",
                    );
                }
            }
        }

        return $findings;
    }

    private function containsCode(string $contents, string $code): bool
    {
        return preg_match('/(?<![a-z0-9_.-])'.preg_quote($code, '/').'(?![a-z0-9_.-])/i', $contents) === 1;
    }
}
