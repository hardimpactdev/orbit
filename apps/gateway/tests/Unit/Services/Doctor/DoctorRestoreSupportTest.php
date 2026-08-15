<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Enums\DoctorIssueDisposition;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Apps\AppsFixer;
use App\Services\Doctor\DnsRuntimeProbe;
use App\Services\Doctor\DoctorIssueCatalog;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorProcessRestorer;
use App\Services\Doctor\DoctorProcessRestoreSupport;
use App\Services\Doctor\DoctorRestoreActionId;
use App\Services\Doctor\DoctorRestoreSupport;
use App\Services\Doctor\DoctorScheduleRestorer;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Nodes\NodesProbe;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Schedules\SchedulesFixer;
use App\Services\Tools\ToolsFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('aggregates only handler-owned restore support sources (not a passive allowlist)', function (): void {
    $aggregated = DoctorRestoreSupport::map();
    $merged = [];

    foreach (DoctorRestoreSupport::sources() as $source) {
        foreach ($source as $code => $action) {
            $merged[$code] = $action;
        }
    }

    ksort($merged);

    expect($aggregated)
        ->toBe($merged)
        ->and($aggregated)
        ->not->toBeEmpty();
});

// The codes app:new points operators at must stay restorable under the public
// `instance.` spelling, not just the internal `app.` one.
it('keeps the public instance security codes restorable', function (): void {
    expect(DoctorRestoreSupport::supports('instance.security.system_user'))
        ->toBeTrue()
        ->and(DoctorRestoreSupport::actionId('instance.security.system_user'))
        ->toBe('restore_app_security_system_user')
        ->and(DoctorRestoreSupport::supports('instance.security.fs_permissions'))
        ->toBeTrue()
        ->and(DoctorRestoreSupport::actionId('instance.security.fs_permissions'))
        ->toBe('restore_app_security_fs_permissions');
});

it('registers every catalog genuine_drift code in DoctorRestoreSupport with matching action ids', function (): void {
    $missing = [];
    $mismatched = [];

    foreach (DoctorIssueCatalog::definitions() as $code => $definition) {
        if ($definition->disposition !== DoctorIssueDisposition::GenuineDrift) {
            continue;
        }

        if (! DoctorRestoreSupport::supports($code)) {
            $missing[] = $code;

            continue;
        }

        if ($definition->restoreAction !== DoctorRestoreSupport::actionId($code)) {
            $mismatched[] = $code;
        }
    }

    expect($missing)
        ->toBeEmpty('Genuine drift without DoctorRestoreSupport: '.implode(', ', $missing))
        ->and($mismatched)
        ->toBeEmpty('restore_action mismatch vs DoctorRestoreSupport: '.implode(', ', $mismatched));
});

it('rejects every non-genuine catalog code from restore support', function (): void {
    $overclaimed = [];

    foreach (DoctorIssueCatalog::definitions() as $code => $definition) {
        if ($definition->disposition === DoctorIssueDisposition::GenuineDrift) {
            continue;
        }

        if (DoctorRestoreSupport::supports($code) || DoctorIssueCatalog::isRestorable($code)) {
            $overclaimed[] = $code;
        }
    }

    expect($overclaimed)
        ->toBeEmpty('Non-genuine codes still restorable/supported: '.implode(', ', $overclaimed));
});

it('only marks support-registered codes restorable', function (): void {
    expect(DoctorIssueCatalog::isRestorable('schedule.heartbeat_stale'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('workspace.path_missing'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('node.wireguard_peer_extra'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('node.access_permission_invalid'))
        ->toBeTrue()
        ->and(DoctorIssueCatalog::isRestorable('app.production_user_missing'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('tool.seaweedfs.credentials_missing'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('schedule.runtime_hibernator_missing'))
        ->toBeTrue()
        ->and(DoctorIssueCatalog::isRestorable('node.role_baseline_mismatch'))
        ->toBeTrue();
});

it('binds node genuine codes to NodesProbe restoreSupport consumed by reconcile', function (): void {
    foreach (array_keys(NodesProbe::restoreSupport()) as $code) {
        expect(DoctorRestoreSupport::supports($code))->toBeTrue("Support missing for {$code}");
    }

    $node = Node::factory()->create(['name' => 'node-probe', 'status' => 'active']);
    assert($node instanceof Node);
    $probe = app(NodesProbe::class);

    // Unsupported key is rejected by the real reconcile dispatch gate.
    expect(fn () => $probe->reconcile(
        $node,
        new DriftEntry(
            family: 'node',
            key: 'node.wireguard_peer_extra',
            kind: DriftKind::Divergent,
            summary: 'not restorable',
        ),
    ))
        ->toThrow(RuntimeException::class, "NodesProbe cannot reconcile drift key 'node.wireguard_peer_extra'");

    // Representative supported key is accepted by the gate (may complete or fail downstream).
    try {
        $probe->reconcile(
            $node,
            new DriftEntry(
                family: 'node',
                key: 'node.agent_expectation_stale',
                kind: DriftKind::Divergent,
                summary: 'stale agent expectation',
            ),
        );
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('cannot reconcile drift key');
    }
});

it('binds schedule gateway codes to SchedulesFixer support and apply routing', function (): void {
    expect(DoctorRestoreSupport::scheduleGatewayCodes())
        ->toBe(SchedulesFixer::GatewayRestorableCodes)
        ->and(array_keys(DoctorRestoreActionId::map(SchedulesFixer::GatewayRestorableCodes)))
        ->toBe(SchedulesFixer::GatewayRestorableCodes);

    $node = Node::factory()->create(['name' => 'gateway', 'status' => 'active']);
    assert($node instanceof Node);
    $restorer = app(DoctorScheduleRestorer::class);

    foreach (SchedulesFixer::GatewayRestorableCodes as $code) {
        if (! str_contains($code, 'hibernator')) {
            continue;
        }

        $result = $restorer->apply(
            $node,
            $code,
            [],
            app(DoctorIssueFactory::class)->fromArray([
                'family' => 'schedule',
                'node' => 'gateway',
                'key' => $code,
                'code' => $code,
                'kind' => DriftKind::Missing->value,
                'summary' => $code,
                'detail' => [],
            ]),
        );

        expect($result)
            ->not
            ->toBeNull("Expected schedule apply routing for {$code}")
            ->and($result['key'] ?? null)
            ->toBe($code)
            ->and($result['mode'] ?? null)
            ->toBe('restore');
    }

    $unsupported = $restorer->apply(
        $node,
        'schedule.heartbeat_stale',
        [],
        app(DoctorIssueFactory::class)->fromArray([
            'family' => 'schedule',
            'node' => 'gateway',
            'key' => 'schedule.heartbeat_stale',
            'code' => 'schedule.heartbeat_stale',
            'kind' => DriftKind::Divergent->value,
            'summary' => 'schedule.heartbeat_stale',
            'detail' => [],
        ]),
    );

    expect($unsupported)->toBeNull();
});

it('binds process genuine codes to DoctorProcessRestoreSupport consumed by DoctorProcessRestorer', function (): void {
    $processCodes = array_keys(DoctorProcessRestoreSupport::restoreSupport());

    expect($processCodes)->not->toBeEmpty();

    foreach ($processCodes as $code) {
        expect(DoctorRestoreSupport::supports($code))->toBeTrue();
        expect(DoctorIssueCatalog::isRestorable($code))->toBeTrue();
    }

    $node = Node::factory()->create(['name' => 'process-node', 'status' => 'active']);
    assert($node instanceof Node);
    expect(app(DoctorProcessRestorer::class)->apply($node, 'process.owner_app_invalid', []))->toBeNull();
});

it('binds generic fixer support to their supported-key sources and rejects unknown keys', function (): void {
    expect(array_keys(ToolsFixer::restoreSupport()))
        ->not->toContain('tool.seaweedfs.credentials_missing')->and(array_keys(AppsFixer::restoreSupport()))
        ->not->toContain('app.production_user_missing')->and(
            array_keys(FirewallRuleFixer::restoreSupport()),
        )->toEqualCanonicalizing(['firewall_rule.rule_missing', 'firewall_rule.rule_mismatch'])->and(
            array_keys(ProxyRouteFixer::restoreSupport()),
        )->toContain('proxy.route_missing')->and(array_keys(DnsRuntimeProbe::restoreSupport()))->toEqualCanonicalizing([
            'tool.dns_container_missing',
            'tool.dns_port_not_listening',
            'tool.dns_base_config_mismatch',
            'tool.dns_client_dns_drift',
            'tool.dns_forwarding_missing',
        ]);

    $node = Node::factory()->create(['name' => 'tool-node', 'status' => 'active']);
    assert($node instanceof Node);
    $tool = NodeTool::factory()->for($node)->create(['name' => 'php-cli']);
    assert($tool instanceof NodeTool);
    $fixer = app(ToolsFixer::class);

    expect($fixer->fix(
        $tool,
        new DriftEntry(
            family: 'tool',
            key: 'tool.seaweedfs.credentials_missing',
            kind: DriftKind::Missing,
            summary: 'no restorer',
        ),
    ))->toBeNull();

    // Explicit keys only — version_mismatch is supported without a catch-all default.
    expect(ToolsFixer::restoreSupport())
        ->toHaveKey('tool.version_mismatch')
        ->and(ToolsFixer::restoreSupport())
        ->toHaveKey('tool.config_missing');
});

it('accepts every genuine code via the real family support decision used by apply', function (): void {
    $unsupportedGenuine = [];

    foreach (DoctorIssueCatalog::definitions() as $code => $definition) {
        if ($definition->disposition !== DoctorIssueDisposition::GenuineDrift) {
            continue;
        }

        $accepted = match (true) {
            str_starts_with($code, 'node.updates') => array_key_exists($code, NodesProbe::restoreSupport()),
            str_starts_with($code, 'node.') => array_key_exists($code, NodesProbe::restoreSupport())
                || array_key_exists($code, \App\Services\Doctor\DoctorDnsProjectionRestoreSupport::restoreSupport()),
            str_starts_with($code, 'schedule.') => in_array($code, SchedulesFixer::GatewayRestorableCodes, true),
            str_starts_with($code, 'process.') => DoctorProcessRestoreSupport::supports($code),
            str_starts_with($code, 'app.') => array_key_exists($code, AppsFixer::restoreSupport()),
            str_starts_with($code, 'instance.') => array_key_exists(
                'app.'.substr($code, 9),
                AppsFixer::restoreSupport(),
            ),
            str_starts_with($code, 'tool.dns_') => array_key_exists($code, DnsRuntimeProbe::restoreSupport()),
            str_starts_with($code, 'tool.') => array_key_exists($code, ToolsFixer::restoreSupport()),
            str_starts_with($code, 'firewall_rule.') => array_key_exists($code, FirewallRuleFixer::restoreSupport()),
            str_starts_with($code, 'database_connection.') => array_key_exists(
                $code,
                \App\Services\DatabaseConnections\DatabaseConnectionRestorer::restoreSupport(),
            ),
            str_starts_with($code, 'proxy.') => DoctorRestoreSupport::supports($code),
            default => DoctorRestoreSupport::supports($code),
        };

        if (! $accepted || ! DoctorRestoreSupport::supports($code)) {
            $unsupportedGenuine[] = $code;
        }
    }

    expect($unsupportedGenuine)
        ->toBeEmpty('Genuine codes not accepted by family dispatch support: '.implode(', ', $unsupportedGenuine));
});

it('does not treat unsupported findings as restore candidates that poison multi-pass', function (): void {
    $node = Node::factory()->create(['name' => 'app-1', 'status' => 'active']);
    assert($node instanceof Node);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => 'workspace',
        'node' => $node->name,
        'key' => 'workspace.path_missing',
        'code' => 'workspace.path_missing',
        'kind' => DriftKind::Missing->value,
        'summary' => 'Workspace path missing.',
        'detail' => ['workspace' => 'x', 'app' => 'y'],
    ])->toArray();

    expect($issue['restorable'] ?? null)
        ->toBeFalse()
        ->and($issue['disposition'] ?? null)
        ->toBe(DoctorIssueDisposition::RuntimeIncident->value)
        ->and($issue['restore_action'] ?? null)
        ->toBeNull();
});
