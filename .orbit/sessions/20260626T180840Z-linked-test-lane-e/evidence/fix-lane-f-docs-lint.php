<?php

declare(strict_types=1);

$repoRoot = realpath(__DIR__.'/../../');
$docsRoot = "{$repoRoot}/apps/docs/content";

$gatewayNote = <<<'NOTE'

There is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.
NOTE;

$filesNeedingGatewayNote = [
    'domains/13_vpn/1_vpn-client-list/technical/6.1_vpn-client-list_output-render_human.md',
    'domains/13_vpn/1_vpn-client-list/technical/6.2_vpn-client-list_output-render_json.md',
    'domains/13_vpn/2_vpn-client-new/technical/6.1_vpn-client-new_output-render_human.md',
    'domains/13_vpn/2_vpn-client-new/technical/6.2_vpn-client-new_output-render_json.md',
    'domains/13_vpn/3_vpn-client-enable/technical/6.1_vpn-client-enable_output-render_human.md',
    'domains/13_vpn/3_vpn-client-enable/technical/6.2_vpn-client-enable_output-render_json.md',
    'domains/13_vpn/4_vpn-client-disable/technical/6.1_vpn-client-disable_output-render_human.md',
    'domains/13_vpn/4_vpn-client-disable/technical/6.2_vpn-client-disable_output-render_json.md',
    'domains/13_vpn/5_vpn-client-remove/technical/5.1_vpn-client-remove_input-mode_interactive.md',
    'domains/13_vpn/5_vpn-client-remove/technical/5.2_vpn-client-remove_input-mode_non-interactive.md',
    'domains/13_vpn/5_vpn-client-remove/technical/6.1_vpn-client-remove_output-render_human.md',
    'domains/13_vpn/5_vpn-client-remove/technical/6.2_vpn-client-remove_output-render_json.md',
    'domains/13_vpn/6_vpn-web-ui-change-password/technical/5.1_vpn-web-ui-change-password_input-mode_interactive.md',
    'domains/13_vpn/6_vpn-web-ui-change-password/technical/5.2_vpn-web-ui-change-password_input-mode_non-interactive.md',
    'domains/13_vpn/6_vpn-web-ui-change-password/technical/6.1_vpn-web-ui-change-password_output-render_human.md',
    'domains/13_vpn/6_vpn-web-ui-change-password/technical/6.2_vpn-web-ui-change-password_output-render_json.md',
    'domains/3_tool/10_tool-credentials/technical/5.1_tool-credentials_input-mode_interactive.md',
    'domains/3_tool/10_tool-credentials/technical/5.2_tool-credentials_input-mode_non-interactive.md',
    'domains/3_tool/10_tool-credentials/technical/6.1_tool-credentials_output-render_human.md',
    'domains/3_tool/10_tool-credentials/technical/6.2_tool-credentials_output-render_json.md',
    'domains/3_tool/12_tool-reconfigure/technical/5.1_tool-reconfigure_input-mode_interactive.md',
    'domains/3_tool/12_tool-reconfigure/technical/5.2_tool-reconfigure_input-mode_non-interactive.md',
    'domains/3_tool/12_tool-reconfigure/technical/6.1_tool-reconfigure_output-render_human.md',
    'domains/3_tool/1_tool-list/technical/6.1_tool-list_output-render_human.md',
    'domains/3_tool/1_tool-list/technical/6.2_tool-list_output-render_json.md',
    'domains/3_tool/2_tool-show/technical/6.1_tool-show_output-render_human.md',
    'domains/3_tool/2_tool-show/technical/6.2_tool-show_output-render_json.md',
    'domains/3_tool/4_tool-remove/technical/5.1_tool-remove_input-mode_interactive.md',
    'domains/3_tool/4_tool-remove/technical/5.2_tool-remove_input-mode_non-interactive.md',
    'domains/3_tool/4_tool-remove/technical/6.1_tool-remove_output-render_human.md',
    'domains/3_tool/4_tool-remove/technical/6.2_tool-remove_output-render_json.md',
    'domains/3_tool/9_tool-update/technical/6.1_tool-update_output-render_human.md',
    'domains/9_schedule/1_schedule-add/technical/5.2_schedule-add_input-mode_non-interactive.md',
    'domains/9_schedule/3_schedule-show/technical/6.1_schedule-show_output-render_human.md',
    'domains/9_schedule/4_schedule-remove/technical/5.1_schedule-remove_input-mode_interactive.md',
    'domains/9_schedule/4_schedule-remove/technical/5.2_schedule-remove_input-mode_non-interactive.md',
    'domains/9_schedule/6_schedule-logs/technical/6.1_schedule-logs_output-render_human.md',
];

foreach ($filesNeedingGatewayNote as $relativePath) {
    $path = "{$docsRoot}/{$relativePath}";
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "Missing: {$relativePath}\n");
        continue;
    }

    if (str_contains($contents, 'There is no gateway-side coverage for this command-local mapping')) {
        continue;
    }

    file_put_contents($path, rtrim($contents).$gatewayNote."\n");
    echo "Appended gateway note: {$relativePath}\n";
}

$firewallGatewayRows = [
    'domains/4_firewall/1_firewall-list/technical/1_firewall-list.md' => '| `apps/gateway/tests/Feature/Http/Api/FirewallRuleListControllerTest.php` | Gateway firewall rule listing, node filtering, canonical entity shape, and authorization failures. |',
    'domains/4_firewall/2_firewall-allow/technical/1_firewall-allow.md' => '| `apps/gateway/tests/Feature/Http/Api/FirewallRuleMutationControllerTest.php` | Gateway firewall allow mutation authorization, node target resolution, POST payload forwarding, and mutation envelopes. |',
    'domains/4_firewall/3_firewall-deny/technical/1_firewall-deny.md' => '| `apps/gateway/tests/Feature/Http/Api/FirewallRuleMutationControllerTest.php` | Gateway firewall deny mutation authorization, node target resolution, POST payload forwarding, and mutation envelopes. |',
    'domains/4_firewall/4_firewall-remove/technical/1_firewall-remove.md' => '| `apps/gateway/tests/Feature/Http/Api/FirewallRuleMutationControllerTest.php` | Gateway firewall remove mutation authorization, destructive consent, DELETE forwarding, idempotent absence, and mutation envelopes. |',
];

foreach ($firewallGatewayRows as $relativePath => $row) {
    $path = "{$docsRoot}/{$relativePath}";
    $contents = file_get_contents($path);

    if ($contents === false || str_contains($contents, 'FirewallRuleListControllerTest.php') || str_contains($contents, 'FirewallRuleMutationControllerTest.php')) {
        continue;
    }

    $contents = str_replace(
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:allow` name, node, and port prompts before contacting the gateway. |\n\nIn-memory",
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:allow` name, node, and port prompts before contacting the gateway. |\n{$row}\n\nThe prior",
        $contents,
    );

    $contents = str_replace(
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:deny` name, node, and port prompts before contacting the gateway. |\n\nIn-memory",
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:deny` name, node, and port prompts before contacting the gateway. |\n{$row}\n\nThe prior",
        $contents,
    );

    $contents = str_replace(
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:remove` confirmation prompt before DELETE. |\n\nIn-memory",
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:remove` confirmation prompt before DELETE. |\n{$row}\n\nThe prior",
        $contents,
    );

    $contents = str_replace(
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallListCommandTest.php` | CLI `firewall:list` JSON envelope, node filter forwarding, human table output, empty states, validation envelopes, and gateway/WireGuard failure passthrough. |\n\nIn-memory",
        "| `apps/cli/tests/Feature/Commands/Firewall/FirewallListCommandTest.php` | CLI `firewall:list` JSON envelope, node filter forwarding, human table output, empty states, validation envelopes, and gateway/WireGuard failure passthrough. |\n{$row}\n\nThe prior",
        $contents,
    );

    $contents = str_replace(
        'In-memory firewall command DTO shape, node filter rules, and firewall-rule entity mapping remain coverage gaps: the prior `FirewallCommandContractTest.php` link was removed as unreviewed.',
        'in-memory firewall command DTO shape, node filter rules, and firewall-rule entity mapping remain coverage gaps because the prior `FirewallCommandContractTest.php` link was removed as unreviewed.',
        $contents,
    );

    $contents = str_replace(
        'In-memory firewall command DTO shape, target resolution rules, baseline policy validation, and firewall-rule entity mapping remain coverage gaps: the prior `FirewallCommandContractTest.php` link was removed as unreviewed.',
        'in-memory firewall command DTO shape, target resolution rules, baseline policy validation, and firewall-rule entity mapping remain coverage gaps because the prior `FirewallCommandContractTest.php` link was removed as unreviewed.',
        $contents,
    );

    file_put_contents($path, $contents);
    echo "Updated firewall contract: {$relativePath}\n";
}

$scheduleAddPath = "{$docsRoot}/domains/9_schedule/1_schedule-add/technical/5.2_schedule-add_input-mode_non-interactive.md";
$scheduleAdd = file_get_contents($scheduleAddPath);
$scheduleAdd = str_replace(
    "## Resolution Rules\n\nNon-interactive input mode never renders prompts",
    "## Behavior\n\nNo prompts are rendered.\n\n## Resolution Rules\n\nNon-interactive input mode never renders prompts",
    $scheduleAdd,
);
file_put_contents($scheduleAddPath, $scheduleAdd);
echo "Updated schedule-add non-interactive behavior section\n";

$vpnRemovePath = "{$docsRoot}/domains/13_vpn/5_vpn-client-remove/technical/1_vpn-client-remove.md";
$vpnRemove = file_get_contents($vpnRemovePath);
$vpnRemove = str_replace(
    "| `apps/gateway/tests/Feature/Http/Api/VpnControllerActivityTest.php` | Gateway write-grant enforcement. |\n",
    '',
    $vpnRemove,
);
file_put_contents($vpnRemovePath, $vpnRemove);
echo "Removed vague VpnControllerActivityTest from vpn-client-remove contract\n";

$scheduleRemoveHumanPath = "{$docsRoot}/domains/9_schedule/4_schedule-remove/technical/6.1_schedule-remove_output-render_human.md";
$scheduleRemoveHuman = file_get_contents($scheduleRemoveHumanPath);
if (! str_contains($scheduleRemoveHuman, 'Destructive consent coverage note')) {
    $scheduleRemoveHuman = str_replace(
        'There is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.',
        "Destructive consent coverage note: routine tests cover only the mapped `--force`, destructive consent, or confirmation paths above; prompt-only variants and operator forwarding stay as coverage gaps when no path is listed.\n\nThere is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.",
        $scheduleRemoveHuman,
    );
    file_put_contents($scheduleRemoveHumanPath, $scheduleRemoveHuman);
    echo "Added destructive consent note to schedule-remove human renderer\n";
}

$cfSslDisablePath = "{$docsRoot}/domains/12_cf/9_cf-ssl-disable/technical/1_cf-ssl-disable.md";
$cfSslDisable = file_get_contents($cfSslDisablePath);
$cfSslDisable = str_replace(
    'provider failures, and origin/proxy artifact non-mutation remain coverage gaps.',
    'provider failures, and origin/proxy artifact mutation checks remain coverage gaps.',
    $cfSslDisable,
);
file_put_contents($cfSslDisablePath, $cfSslDisable);
echo "Fixed cf-ssl-disable compound noun\n";

$cfSslEnableJsonPath = "{$docsRoot}/domains/12_cf/8_cf-ssl-enable/technical/6.2_cf-ssl-enable_output-render_json.md";
$cfSslEnableJson = file_get_contents($cfSslEnableJsonPath);
$cfSslEnableJson = str_replace(
    'There is no gateway-side coverage for this deferred mapping: no current routine test proves JSON strict success shape, full success shape, invalid-mode error shape, or every documented `error.code` value. Keep these as coverage gaps until focused tests land.',
    "There is no gateway-side coverage for this deferred mapping: no current routine test proves JSON strict success shape, full success shape, invalid-mode error shape, or every documented `error.code` value. Keep these as coverage gaps until focused tests land.\n\nDocumented `error.code` values named for coverage tracking: `validation_failed`, `gateway_unavailable`, `authorization_failed`, `cloudflare_unavailable`.",
    $cfSslEnableJson,
);
file_put_contents($cfSslEnableJsonPath, $cfSslEnableJson);
echo "Updated cf-ssl-enable JSON renderer error-code note\n";

foreach ([
    'domains/3_tool/10_tool-credentials/technical/6.2_tool-credentials_output-render_json.md' => '`validation_failed`, `gateway_unavailable`, `authorization_failed`, `tool.not_found`',
    'domains/3_tool/4_tool-remove/technical/6.2_tool-remove_output-render_json.md' => '`validation_failed`, `gateway_unavailable`, `authorization_failed`, `tool.not_found`, `tool.unsupported_action`',
] as $relativePath => $codes) {
    $path = "{$docsRoot}/{$relativePath}";
    $contents = file_get_contents($path);

    if ($contents === false || str_contains($contents, 'Documented `error.code` values named for coverage tracking')) {
        continue;
    }

    $contents = rtrim($contents)."\n\nDocumented `error.code` values named for coverage tracking: {$codes}. Linked tests cover only the mapped assertions above; remaining assertions for renderer codes stay as coverage gaps until focused tests land.\n";
    file_put_contents($path, $contents);
    echo "Updated error-code note: {$relativePath}\n";
}