<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

$gapDeferred = <<<'NOTE'
There is no gateway-side coverage for this deferred mapping: no current routine test proves this documented behavior. Keep it as a coverage gap until a focused test lands.
NOTE;

$gapCliOnly = <<<'NOTE'
There is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.
NOTE;

$gapOperator = <<<'NOTE'
There is no gateway-side coverage for this deferred mapping: no current routine test proves operator-node WireGuard forwarding. Keep it as a coverage gap until retained-topology proof exists.
NOTE;

$gapJsonSubset = <<<'NOTE'
There is no gateway-side coverage for this renderer: JSON envelope rendering is owned by `apps/cli`. Documented error.code values are not exhaustively asserted by the linked CLI tests unless the rows above name them explicitly.
NOTE;

$gapJsonEnvelope = <<<'NOTE'
There is no gateway-side coverage for this deferred mapping: no current routine test discriminates JSON envelope variants for this command. Keep it as a coverage gap until a focused test lands.
NOTE;

$gapE2e = <<<'NOTE'
There is no routine test mapping for E2E smoke coverage: retained-topology and artifact-backed E2E lanes are manual-only and must not be linked from product docs.
NOTE;

$gapCfFlushApi = <<<'NOTE'
Gateway `flushCache` API behavior has no dedicated routine feature test. CLI forwarding, zone resolution, and renderer output are covered by the linked CLI tests above.
NOTE;

function tableRow(string $path, string $coverage): string
{
    return "| `{$path}` | {$coverage} |";
}

function mappingSection(string $body): string
{
    return "## Test Mapping\n\n{$body}";
}

function gapOnly(string $note): string
{
    return mappingSection("| Path | Coverage |\n| --- | --- |\n\n{$note}");
}

function tableMapping(array $rows, ?string $note = null, ?string $footer = null): string
{
    $lines = ["| Path | Coverage |", "| --- | --- |"];
    foreach ($rows as [$path, $coverage]) {
        $lines[] = tableRow($path, $coverage);
    }

    $body = implode("\n", $lines);

    if ($note !== null) {
        $body .= "\n\n{$note}";
    }

    if ($footer !== null) {
        $body .= "\n\n{$footer}";
    }

    return mappingSection($body);
}

function primaryOwners(array $rows, ?string $note = null, ?string $footer = null): string
{
    $table = tableMapping($rows, $note, $footer);

    return str_replace('## Test Mapping', "## Test Mapping\n\nPrimary test owners:", $table);
}

function replaceTestMapping(string $content, string $replacement): string
{
    if (! preg_match('/\n## Test Mapping\n/s', $content)) {
        throw new RuntimeException('Missing Test Mapping section');
    }

    return preg_replace('/\n## Test Mapping\n.*/s', "\n".$replacement, $content) ?? $content;
}

$cliWrite = 'apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php';
$cliDefault = 'apps/cli/tests/Feature/Commands/Node/NodeDefaultCommandTest.php';
$cliShow = 'apps/cli/tests/Feature/Commands/Node/NodeShowCommandTest.php';
$cliRoleList = 'apps/cli/tests/Feature/Commands/Node/NodeRoleListCommandTest.php';
$cliNewStream = 'apps/cli/tests/Feature/Commands/Node/NodeNewStreamCommandTest.php';
$cliAgentIdeMsg = 'apps/cli/tests/Feature/Commands/AgentIde/AgentIdeMessageCommandTest.php';
$cliCfRead = 'apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php';
$cliCfWrite = 'apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php';
$cliCfRender = 'apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php';
$cliDoctor = 'apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php';

$apiAgentIdeMsg = 'apps/gateway/tests/Feature/Http/Api/AgentIdeMessageControllerTest.php';
$apiCf = 'apps/gateway/tests/Feature/Http/Api/CloudflareControllerTest.php';
$apiDoctor = 'apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php';
$apiRoleAdd = 'apps/gateway/tests/Feature/Http/Api/NodeRoleAddControllerTest.php';
$apiRoleList = 'apps/gateway/tests/Feature/Http/Api/NodeRoleListControllerTest.php';
$apiRoleRemove = 'apps/gateway/tests/Feature/Http/Api/NodeRoleRemoveControllerTest.php';
$apiNodeAgentIde = 'apps/gateway/tests/Feature/Http/Api/NodeAgentIdeControllerTest.php';
$apiGrant = 'apps/gateway/tests/Feature/Http/Api/NodeGrantControllerTest.php';
$apiStore = 'apps/gateway/tests/Feature/Http/Api/NodeStoreControllerTest.php';
$apiStoreStream = 'apps/gateway/tests/Feature/Http/Api/NodeStoreStreamControllerTest.php';
$apiPermissions = 'apps/gateway/tests/Feature/Http/Api/NodePermissionsControllerTest.php';
$apiRemove = 'apps/gateway/tests/Feature/Http/Api/NodeRemoveControllerTest.php';
$apiRemoveDns = 'apps/gateway/tests/Feature/Http/Api/NodeRemoveDevelopmentDnsWarningTest.php';
$apiRevoke = 'apps/gateway/tests/Feature/Http/Api/NodeRevokeControllerTest.php';
$apiShow = 'apps/gateway/tests/Feature/Http/Api/NodeShowControllerTest.php';
$apiUpdate = 'apps/gateway/tests/Feature/Http/Api/NodeUpdateControllerTest.php';
$unitDoctorRunner = 'apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php';

$splitInput = static function (string $interactive, string $nonInteractive): string {
    return "Input-mode-specific test mapping lives in:\n\n- [`{$interactive}`]({$interactive}#test-mapping)\n- [`{$nonInteractive}`]({$nonInteractive}#test-mapping)";
};

$splitRenderer = static function (string $human, string $json): string {
    return "Renderer-specific test mapping lives in:\n\n- [`{$human}`]({$human}#test-mapping)\n- [`{$json}`]({$json}#test-mapping)";
};

$splitDeploy = static function (string $client, string $gateway): string {
    return "Deployment-context-specific test mapping lives in:\n\n- [`{$client}`]({$client}#test-mapping)\n- [`{$gateway}`]({$gateway}#test-mapping)";
};

$mappings = [
    'apps/docs/content/domains/15_agent-ide/1_agent-ide-message/technical/1_agent-ide-message.md' => primaryOwners([
        [$cliAgentIdeMsg, 'CLI target resolution, stdin delivery, validation before gateway contact, human and JSON renderer output, and gateway error passthrough.'],
        [$apiAgentIdeMsg, 'Gateway delivery authorization, adapter diagnostics, accepted delivery success, and authorization denial.'],
    ], null, $splitInput('5.1_agent-ide-message_input-mode_interactive.md', '5.2_agent-ide-message_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_agent-ide-message_output-render_human.md', '6.2_agent-ide-message_output-render_json.md')),

    'apps/docs/content/domains/15_agent-ide/1_agent-ide-message/technical/5.1_agent-ide-message_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/15_agent-ide/1_agent-ide-message/technical/5.2_agent-ide-message_input-mode_non-interactive.md' => tableMapping([
        [$cliAgentIdeMsg, 'Non-interactive missing and conflicting target input rejected before gateway IO.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/15_agent-ide/1_agent-ide-message/technical/6.1_agent-ide-message_output-render_human.md' => tableMapping([
        [$cliAgentIdeMsg, 'Human success prose for app and workspace delivery.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/15_agent-ide/1_agent-ide-message/technical/6.2_agent-ide-message_output-render_json.md' => tableMapping([
        [$cliAgentIdeMsg, 'JSON envelope success and gateway error data passthrough.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/12_cf/1_cf-zone-list/technical/1_cf-zone-list.md' => primaryOwners([
        [$cliCfRead, 'CLI zone list forwarding, human table and empty-state output, and JSON meta count output.'],
        [$apiCf, 'Gateway zone listing authorization, provider authorization, Cloudflare token failures, and empty zone lists.'],
    ], null, $splitRenderer('6.1_cf-zone-list_output-render_human.md', '6.2_cf-zone-list_output-render_json.md')),
    'apps/docs/content/domains/12_cf/1_cf-zone-list/technical/6.1_cf-zone-list_output-render_human.md' => tableMapping([
        [$cliCfRead, 'Human table output and empty-state prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/12_cf/1_cf-zone-list/technical/6.2_cf-zone-list_output-render_json.md' => tableMapping([
        [$cliCfRead, 'JSON success envelope and meta count output.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/12_cf/5_cf-cache-flush/technical/1_cf-cache-flush.md' => primaryOwners([
        [$cliCfWrite, 'CLI zone and app resolution, interactive zone prompt, non-interactive missing-zone validation, and gateway forwarding.'],
        [$cliCfRender, 'Human progress-tree flush output and JSON validation and error rendering.'],
    ], $gapCfFlushApi, $splitInput('5.1_cf-cache-flush_input-mode_interactive.md', '5.2_cf-cache-flush_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_cf-cache-flush_output-render_human.md', '6.2_cf-cache-flush_output-render_json.md')),
    'apps/docs/content/domains/12_cf/5_cf-cache-flush/technical/5.1_cf-cache-flush_input-mode_interactive.md' => tableMapping([
        [$cliCfWrite, 'Interactive zone prompt and validation before gateway contact.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/12_cf/5_cf-cache-flush/technical/5.2_cf-cache-flush_input-mode_non-interactive.md' => tableMapping([
        [$cliCfWrite, 'Non-interactive missing-zone validation and forwarding.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/12_cf/5_cf-cache-flush/technical/6.1_cf-cache-flush_output-render_human.md' => tableMapping([
        [$cliCfRender, 'Human progress-tree flush output.'],
        [$cliCfWrite, 'Gateway failure prose passthrough.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/12_cf/5_cf-cache-flush/technical/6.2_cf-cache-flush_output-render_json.md' => tableMapping([
        [$cliCfRender, 'JSON validation and error envelope rendering.'],
        [$cliCfWrite, 'Gateway error passthrough for flush failures.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-client.md' => primaryOwners([
        [$cliDoctor, 'CLI scope selection, panel rendering, JSON and stream output, and gateway request forwarding from client context.'],
        [$apiDoctor, 'Gateway verify scope, authorization failure handling, and diagnostic response shape.'],
        [$unitDoctorRunner, 'Role-aware category sets, universal process-family support for role-bearing nodes, and family scope validation.'],
    ]),
    'apps/docs/content/domains/11_operation/3_doctor/technical/3_doctor_on-gateway-node.md' => primaryOwners([
        [$cliDoctor, 'Gateway-node doctor invocation, scope forwarding, and rendered output from gateway context.'],
        [$apiDoctor, 'Gateway verify authorization, scope validation, and diagnostic payload responses.'],
    ]),
    'apps/docs/content/domains/11_operation/3_doctor/technical/7_doctor_scope-and-authorization.md' => primaryOwners([
        [$cliDoctor, 'Mutually exclusive flag rejection, unsupported family rejection, and authorization failure handling at the CLI boundary.'],
        [$apiDoctor, 'Gateway verify scope enforcement and authorization failures before probes run.'],
    ]),

    'apps/docs/content/domains/1_node/node-doctor.md' => null,

    'apps/docs/content/domains/1_node/9_node-default/technical/1_node-default.md' => primaryOwners([
        [$cliDefault, 'Interactive choose, show/set/clear sub-actions, mutually exclusive input rejection, gateway-unavailable failures, local-only write guarantee, human renderer prose, and JSON envelope shape.'],
    ], null, $splitInput('5.1_node-default_input-mode_interactive.md', '5.2_node-default_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-default_output-render_human.md', '6.2_node-default_output-render_json.md')."\n\n".$splitDeploy('2_node-default_on-client.md', '3_node-default_on-gateway-node.md')),
    'apps/docs/content/domains/1_node/9_node-default/technical/5.1_node-default_input-mode_interactive.md' => tableMapping([
        [$cliDefault, 'Interactive choose from authorized development app-role choices.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/9_node-default/technical/5.2_node-default_input-mode_non-interactive.md' => tableMapping([
        [$cliDefault, 'Show, set, and clear sub-actions plus mutually exclusive name and --clear validation.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/9_node-default/technical/6.1_node-default_output-render_human.md' => tableMapping([
        [$cliDefault, 'Human choose, show, set, clear, empty-state, and error prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/9_node-default/technical/6.2_node-default_output-render_json.md' => tableMapping([
        [$cliDefault, 'JSON show, set, clear, and validation envelopes.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/9_node-default/technical/2_node-default_on-client.md' => tableMapping([
        [$cliDefault, 'Local show and clear behavior plus set validation through mocked gateway list reads.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/9_node-default/technical/3_node-default_on-gateway-node.md' => tableMapping([
        [$cliDefault, 'Local-only default-node configuration with no gateway default routes.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/11_node-role-list/technical/1_node-role-list.md' => primaryOwners([
        [$cliRoleList, 'CLI default-node resolution, human and JSON list output.'],
        [$apiRoleList, 'Gateway role list authorization, list shape, and not-found handling.'],
    ]),
    'apps/docs/content/domains/1_node/11_node-role-list/technical/6.2_node-role-list_output-render_json.md' => tableMapping([
        [$cliRoleList, 'JSON role list envelope and default-node resolution.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/12_node-role-add/technical/1_node-role-add.md' => primaryOwners([
        [$cliWrite, 'CLI role:add post, render, and validation before gateway contact.'],
        [$apiRoleAdd, 'Gateway role add, reconverge behavior, and gateway-role rejection.'],
    ], null, $splitInput('5.1_node-role-add_input-mode_interactive.md', '5.2_node-role-add_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-role-add_output-render_human.md', '6.2_node-role-add_output-render_json.md')),
    'apps/docs/content/domains/1_node/12_node-role-add/technical/5.1_node-role-add_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/12_node-role-add/technical/5.2_node-role-add_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive role:add validation and forwarding.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/12_node-role-add/technical/6.1_node-role-add_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human role:add success and failure prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/12_node-role-add/technical/6.2_node-role-add_output-render_json.md' => gapOnly($gapJsonEnvelope),

    'apps/docs/content/domains/1_node/14_node-role-remove/technical/1_node-role-remove.md' => primaryOwners([
        [$cliWrite, 'CLI role:remove force and purge rendering plus validation before gateway contact.'],
        [$apiRoleRemove, 'Gateway blocked removal, force removal, and purge cleanup behavior.'],
    ], null, $splitInput('5.1_node-role-remove_input-mode_interactive.md', '5.2_node-role-remove_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-role-remove_output-render_human.md', '6.2_node-role-remove_output-render_json.md')),
    'apps/docs/content/domains/1_node/14_node-role-remove/technical/5.1_node-role-remove_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/14_node-role-remove/technical/5.2_node-role-remove_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive role:remove validation and forwarding.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/14_node-role-remove/technical/6.1_node-role-remove_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human role:remove success and failure prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/14_node-role-remove/technical/6.2_node-role-remove_output-render_json.md' => gapOnly($gapJsonEnvelope),

    'apps/docs/content/domains/1_node/10_node-agent-ide/technical/1_node-agent-ide.md' => primaryOwners([
        [$cliWrite, 'CLI set, clear, and converged rendering plus validation before gateway contact.'],
        [$apiNodeAgentIde, 'Gateway grant, validation, not-found, and unsupported adapter handling.'],
    ], null, $splitInput('5.1_node-agent-ide_input-mode_interactive.md', '5.2_node-agent-ide_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-agent-ide_output-render_human.md', '6.2_node-agent-ide_output-render_json.md')),
    'apps/docs/content/domains/1_node/10_node-agent-ide/technical/5.1_node-agent-ide_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/10_node-agent-ide/technical/5.2_node-agent-ide_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Explicit adapter arguments required before gateway contact.'],
        [$apiNodeAgentIde, 'Gateway missing and unsupported adapter errors.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/10_node-agent-ide/technical/6.1_node-agent-ide_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human set, converged, clear, and failure prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/10_node-agent-ide/technical/6.2_node-agent-ide_output-render_json.md' => tableMapping([
        [$cliWrite, 'JSON posting envelopes and gateway error passthrough.'],
        [$apiNodeAgentIde, 'Gateway success and validation envelopes.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/5_node-grant/technical/1_node-grant.md' => primaryOwners([
        [$cliWrite, 'CLI grant create, idempotence, elevated-grant consent, self-grants, human renderer prose, and JSON envelopes covered by the linked subset of documented error codes.'],
        [$apiGrant, 'Gateway grant authorization, validation failures, idempotence, and warning payloads.'],
    ], null, $splitRenderer('6.1_node-grant_output-render_human.md', '6.2_node-grant_output-render_json.md')."\n\n".$splitDeploy('2_node-grant_on-client.md', '3_node-grant_on-gateway-node.md')),
    'apps/docs/content/domains/1_node/5_node-grant/technical/2_node-grant_on-client.md' => tableMapping([
        [$cliWrite, 'Client-context grant forwarding and rendered success and failure output.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/5_node-grant/technical/3_node-grant_on-gateway-node.md' => gapOnly($gapOperator),
    'apps/docs/content/domains/1_node/5_node-grant/technical/6.1_node-grant_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human grant, idempotent, and warning prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/5_node-grant/technical/6.2_node-grant_output-render_json.md' => tableMapping([
        [$cliWrite, 'JSON grant success, validation, and warning envelopes for the covered error-code subset.'],
        [$apiGrant, 'Gateway grant validation and authorization envelopes.'],
    ], $gapJsonSubset),

    'apps/docs/content/domains/1_node/1_node-new/technical/1_node-new.md' => primaryOwners([
        [$cliWrite, 'Canonical input contract validation, role mutual exclusion, canonical role validation, non-interactive normalization, and JSON complete frames.'],
        [$apiStore, 'Gateway node store authorization and app-dev provisioning.'],
        [$apiStoreStream, 'Gateway streamed node creation and SSE creation frames.'],
    ], $gapE2e, $splitInput('5.1_node-new_input-mode_interactive.md', '5.2_node-new_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-new_output-render_human.md', '6.2_node-new_output-render_json.md')."\n\n".$splitDeploy('2_node-new_on-client.md', '3_node-new_on-gateway-node.md')),
    'apps/docs/content/domains/1_node/1_node-new/technical/2_node-new_on-client.md' => tableMapping([
        [$cliWrite, 'Client-context node:new forwarding and validation before gateway contact.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/1_node-new/technical/3_node-new_on-gateway-node.md' => gapOnly($gapOperator),
    'apps/docs/content/domains/1_node/1_node-new/technical/5.1_node-new_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/1_node-new/technical/5.2_node-new_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive validation, normalization, and forwarding.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/1_node-new/technical/6.1_node-new_output-render_human.md' => tableMapping([
        [$cliNewStream, 'Streamed progress tree and footer assertions.'],
        [$cliWrite, 'Human renderer failure prose passthrough.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md' => tableMapping([
        [$cliWrite, 'JSON complete frame envelopes.'],
        [$cliNewStream, 'Stream failure envelope rendering.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/15_node-permissions/technical/1_node-permissions.md' => primaryOwners([
        [$cliWrite, 'CLI permissions mode selection, render output, and validation before gateway contact.'],
        [$apiPermissions, 'Gateway read, replace, add, remove, and authorization handling.'],
    ], null, $splitInput('5.1_node-permissions_input-mode_interactive.md', '5.2_node-permissions_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-permissions_output-render_human.md', '6.2_node-permissions_output-render_json.md')),
    'apps/docs/content/domains/1_node/15_node-permissions/technical/5.1_node-permissions_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/15_node-permissions/technical/5.2_node-permissions_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive permissions validation and forwarding.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/15_node-permissions/technical/6.1_node-permissions_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human permissions success and failure prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/15_node-permissions/technical/6.2_node-permissions_output-render_json.md' => gapOnly($gapJsonEnvelope),

    'apps/docs/content/domains/1_node/8_node-remove/technical/1_node-remove.md' => primaryOwners([
        [$cliWrite, 'CLI delete forwarding, force gating, human and JSON renderer output, and lifecycle validation before gateway contact.'],
        [$apiRemove, 'Gateway remove authorization, force removal, self-removal denial, and delete envelopes.'],
        [$apiRemoveDns, 'Development DNS warning payload when removing development app-role nodes.'],
    ], null, $splitInput('5.1_node-remove_input-mode_interactive.md', '5.2_node-remove_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-remove_output-render_human.md', '6.2_node-remove_output-render_json.md')."\n\n".$splitDeploy('2_node-remove_on-client.md', '3_node-remove_on-gateway-node.md')),
    'apps/docs/content/domains/1_node/8_node-remove/technical/2_node-remove_on-client.md' => tableMapping([
        [$cliWrite, 'Client-context node:remove forwarding and rendered output.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/8_node-remove/technical/3_node-remove_on-gateway-node.md' => gapOnly($gapOperator),
    'apps/docs/content/domains/1_node/8_node-remove/technical/5.1_node-remove_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/8_node-remove/technical/5.2_node-remove_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive --force gating and name validation.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/8_node-remove/technical/6.1_node-remove_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human progress tree and authorization failure prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/8_node-remove/technical/6.2_node-remove_output-render_json.md' => tableMapping([
        [$cliWrite, 'JSON delete envelopes and gateway error passthrough.'],
        [$apiRemove, 'Gateway delete success and authorization envelopes.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/6_node-revoke/technical/1_node-revoke.md' => primaryOwners([
        [$cliWrite, 'CLI revoke forwarding, idempotent behavior, self-lockout consent, human renderer prose, and JSON envelopes.'],
        [$apiRevoke, 'Gateway revoke authorization, idempotence, and validation envelopes.'],
    ], null, $splitInput('5.1_node-revoke_input-mode_interactive.md', '5.2_node-revoke_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-revoke_output-render_human.md', '6.2_node-revoke_output-render_json.md')."\n\n".$splitDeploy('2_node-revoke_on-client.md', '3_node-revoke_on-gateway-node.md')),
    'apps/docs/content/domains/1_node/6_node-revoke/technical/2_node-revoke_on-client.md' => tableMapping([
        [$cliWrite, 'Client-context node:revoke forwarding and rendered output.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/6_node-revoke/technical/3_node-revoke_on-gateway-node.md' => gapOnly($gapOperator),
    'apps/docs/content/domains/1_node/6_node-revoke/technical/5.1_node-revoke_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/6_node-revoke/technical/5.2_node-revoke_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive missing --force and argument validation.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/6_node-revoke/technical/6.1_node-revoke_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human revoke, idempotent, and self-lockout prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/6_node-revoke/technical/6.2_node-revoke_output-render_json.md' => tableMapping([
        [$cliWrite, 'JSON revoke envelopes and gateway error passthrough.'],
        [$apiRevoke, 'Gateway revoke success and validation envelopes.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/4_node-show/technical/1_node-show.md' => primaryOwners([
        [$cliShow, 'CLI default resolution, human field rendering, JSON envelope output, and missing-name validation.'],
        [$apiShow, 'Gateway registry read, not-found handling, and authorization failures.'],
    ], null, $splitInput('5.1_node-show_input-mode_interactive.md', '5.2_node-show_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-show_output-render_human.md', '6.2_node-show_output-render_json.md')),
    'apps/docs/content/domains/1_node/4_node-show/technical/5.1_node-show_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/4_node-show/technical/5.2_node-show_input-mode_non-interactive.md' => tableMapping([
        [$cliShow, 'Default-node resolution and missing-name validation.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/4_node-show/technical/6.1_node-show_output-render_human.md' => tableMapping([
        [$cliShow, 'Human field rendering for resolved nodes.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/4_node-show/technical/6.2_node-show_output-render_json.md' => tableMapping([
        [$cliShow, 'Canonical JSON success and error envelopes.'],
    ], $gapCliOnly),

    'apps/docs/content/domains/1_node/7_node-update/technical/1_node-update.md' => primaryOwners([
        [$cliWrite, 'CLI update post, render, drift reporting, human renderer prose, non-interactive required-input validation, and JSON envelopes.'],
        [$apiUpdate, 'Gateway field updates, TLD handling, no-op updates, and authorization envelopes.'],
    ], null, $splitInput('5.1_node-update_input-mode_interactive.md', '5.2_node-update_input-mode_non-interactive.md')."\n\n".$splitRenderer('6.1_node-update_output-render_human.md', '6.2_node-update_output-render_json.md')."\n\n".$splitDeploy('2_node-update_on-client.md', '3_node-update_on-gateway-node.md')),
    'apps/docs/content/domains/1_node/7_node-update/technical/2_node-update_on-client.md' => tableMapping([
        [$cliWrite, 'Client-context node:update forwarding and rendered output.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/7_node-update/technical/3_node-update_on-gateway-node.md' => gapOnly($gapOperator),
    'apps/docs/content/domains/1_node/7_node-update/technical/5.1_node-update_input-mode_interactive.md' => gapOnly($gapDeferred),
    'apps/docs/content/domains/1_node/7_node-update/technical/5.2_node-update_input-mode_non-interactive.md' => tableMapping([
        [$cliWrite, 'Non-interactive required-input validation dataset.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/7_node-update/technical/6.1_node-update_output-render_human.md' => tableMapping([
        [$cliWrite, 'Human update, no-op, and drift prose.'],
    ], $gapCliOnly),
    'apps/docs/content/domains/1_node/7_node-update/technical/6.2_node-update_output-render_json.md' => tableMapping([
        [$cliWrite, 'JSON update envelopes and gateway error passthrough.'],
        [$apiUpdate, 'Gateway update success and validation envelopes.'],
    ], $gapCliOnly),
];

$changed = [];
$skipped = [];

foreach ($mappings as $relativePath => $replacement) {
    $absolutePath = "{$repoRoot}/{$relativePath}";

    if (! is_file($absolutePath)) {
        $skipped[] = "{$relativePath} (missing file)";
        continue;
    }

    $content = file_get_contents($absolutePath);

    if ($relativePath === 'apps/docs/content/domains/1_node/node-doctor.md') {
        $replacement = primaryOwners([
            [$cliDoctor, 'CLI doctor scope selection and rendered output when node doctor sections are exercised from the CLI.'],
            [$apiDoctor, 'Gateway verify scope and authorization when node-family doctor probes run through the API.'],
        ]);
    }

    if ($replacement === null) {
        $skipped[] = "{$relativePath} (null replacement)";
        continue;
    }

    $updated = replaceTestMapping($content, $replacement);

    if ($updated === $content) {
        $skipped[] = "{$relativePath} (no change)";
        continue;
    }

    file_put_contents($absolutePath, $updated);
    $changed[] = $relativePath;
}

echo json_encode([
    'changed_count' => count($changed),
    'changed' => $changed,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;