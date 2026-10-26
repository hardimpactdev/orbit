<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

describe(VpInventoryInspector::class, function (): void {
    it('maps Vite+ observations to adoption policy and registrations without writing', function (): void {
        $node = vp_inventory_node();
        $other = vp_inventory_node('vp-inventory-other', '10.8.0.48');
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $composer = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer->value,
            'status' => LifecycleStatus::Active,
        ]);
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $otherVp = $other->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $codex = vp_inventory_tool($node, $vp, '@openai/codex');
        vp_inventory_tool($node, $composer, '@openai/codex');
        vp_inventory_tool($node, $brew, 'pnpm');
        vp_inventory_tool($other, $otherVp, '@openai/codex');
        [$inspector, $agent] = vp_inventory_inspector([
            ['name' => 'typescript', 'version' => 'latest'],
            ['name' => 'pnpm', 'version' => '12.0.0'],
            ['name' => '@openai/codex', 'version' => '0.150.0'],
            ['name' => '@anthropic-ai/claude-code', 'version' => '1.0.24'],
        ]);
        $toolsBefore = Tool::query()->orderBy('id')->get(['id', 'package', 'installed_version', 'status'])->toArray();
        $managersBefore = ToolManagerRecord::query()->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $scan = $inspector->inspect($node);
        $queries = DB::getQueryLog();

        expect($scan->manager)->toBe(ToolManagerName::Vp)
            ->and($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and(array_map(static fn (ToolInventoryPackage $package): string => $package->package, $scan->packages))
            ->toBe(['@anthropic-ai/claude-code', '@openai/codex', 'pnpm', 'typescript'])
            ->and($scan->packages[0]->installedVersion)->toBe('1.0.24')
            ->and($scan->packages[0]->packageKind)->toBe(ToolInventoryPackageKind::Global)
            ->and($scan->packages[0]->dependency)->toBeFalse()
            ->and($scan->packages[0]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scan->packages[0]->adoptionBlock)->toBeNull()
            ->and($scan->packages[0]->registered)->toBeFalse()
            ->and($scan->packages[0]->toolId)->toBeNull()
            ->and($scan->packages[1]->registered)->toBeTrue()
            ->and($scan->packages[1]->toolId)->toBe($codex->id)
            ->and($scan->packages[1]->installedVersion)->toBe('0.150.0')
            ->and($scan->packages[2]->package)->toBe('pnpm')
            ->and($scan->packages[2]->adoption)->toBe(ToolInventoryPackage::UNSUPPORTED)
            ->and($scan->packages[2]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_PROTECTED)
            ->and($scan->packages[2]->registered)->toBeFalse()
            ->and($scan->packages[3]->installedVersion)->toBeNull()
            ->and($scan->packages[3]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_VERSION)
            ->and(ToolManagerRecord::query()->count())->toBe($managersBefore)
            ->and(Tool::query()->orderBy('id')->get(['id', 'package', 'installed_version', 'status'])->toArray())
            ->toBe($toolsBefore);

        expect($agent->commands())->toBe(['tools.vp-inventory']);
        expect($agent->calls[0]['input'])->toBe([]);
        foreach ($queries as $query) {
            expect(strtolower((string) $query['query']))->not->toMatch('/\b(insert|update|delete|replace)\b/');
        }
    });

    it('maps absent conflicting and failed inventory without changing records', function (string $code, ToolInventoryScanState $state): void {
        [$inspector, $agent] = vp_inventory_inspector([]);
        $agent->refuse('tools.vp-inventory', $code);
        $scan = $inspector->inspect(vp_inventory_node());
        expect($scan->scanState)->toBe($state);
        expect($scan->packages)->toBe([]);
        expect($agent->commands())->toBe(['tools.vp-inventory']);
    })->with([
        ['tools.vp_scope_absent', ToolInventoryScanState::Absent],
        ['tools.vp_scope_conflict', ToolInventoryScanState::Conflicting],
        ['tools.vp_scope_unreadable', ToolInventoryScanState::Incomplete],
        ['tools.vp_output_invalid', ToolInventoryScanState::Incomplete],
        ['tools.vp_operation_failed', ToolInventoryScanState::Incomplete],
    ]);

    it('reports a lost agent result as an incomplete scan', function (): void {
        [$inspector, $agent] = vp_inventory_inspector([]);
        $agent->answer('tools.vp-inventory', static fn (): array => throw new AgentRunnerFailure('tools.vp-inventory', 'transport', 'Private diagnostic'));
        $scan = $inspector->inspect(vp_inventory_node());
        expect($scan->scanState)->toBe(ToolInventoryScanState::Incomplete);
        expect($scan->packages)->toBe([]);
    });

    it('lists a legacy npm name as unsupported without hiding the other globals', function (): void {
        $node = vp_inventory_node();
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $codex = vp_inventory_tool($node, $vp, '@openai/codex', '0.150.0');
        $legacy = vp_inventory_tool($node, $vp, 'JSONStream', '1.3.5');
        [$inspector] = vp_inventory_inspector([
            ['name' => 'JSONStream', 'version' => '1.3.5'],
            ['name' => '@openai/codex', 'version' => '0.150.0'],
        ]);

        $scan = $inspector->inspect($node);

        expect($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and(array_map(static fn (ToolInventoryPackage $package): string => $package->package, $scan->packages))
            ->toBe(['@openai/codex', 'JSONStream'])
            ->and($scan->packages[0]->registered)->toBeTrue()
            ->and($scan->packages[0]->toolId)->toBe($codex->id)
            ->and($scan->packages[0]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scan->packages[1]->adoption)->toBe(ToolInventoryPackage::UNSUPPORTED)
            ->and($scan->packages[1]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($scan->packages[1]->registered)->toBeFalse()
            ->and($scan->packages[1]->toolId)->toBeNull()
            ->and($scan->packages[1]->installedVersion)->toBe('1.3.5')
            ->and($scan->packages[1]->dependency)->toBeFalse()
            ->and($scan->packages[1]->packageKind)->toBe(ToolInventoryPackageKind::Global)
            ->and($legacy->refresh()->package)->toBe('JSONStream');
    });

    it('keeps an unsupported platform offline without a command', function (): void {
        [$inspector, $ssh] = vp_inventory_inspector([]);

        $scan = $inspector->inspect(vp_inventory_node(platform: 'freebsd', saved: false));

        expect($scan->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and($scan->packages)->toBe([])
            ->and($ssh->calls)->toBe([]);
    });

    it('treats an empty global list as a complete inventory and keeps unregistered rows out', function (): void {
        $node = vp_inventory_node();
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        vp_inventory_tool($node, $brew, '@openai/codex');
        [$inspector] = vp_inventory_inspector([]);

        $scan = $inspector->inspect($node);

        expect($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scan->packages)->toBe([])
            ->and(Tool::query()->count())->toBe(1);
    });

    it('reports an incomplete scan without hiding registered Tool state', function (): void {
        $node = vp_inventory_node();
        $vp = $node->toolManagers()->create(['name' => ToolManagerName::Vp->value, 'status' => LifecycleStatus::Active]);
        $tool = vp_inventory_tool($node, $vp, '@openai/codex', '0.150.0');
        [$inspector, $agent] = vp_inventory_inspector([['name' => '@openai/codex', 'version' => null]], false);
        $scan = $inspector->inspect($node);
        expect($scan->scanState)->toBe(ToolInventoryScanState::Incomplete);
        expect($scan->packages)->toBe([]);
        expect($tool->refresh()->installed_version)->toBe('0.150.0');
        expect($tool->status)->toBe(ToolStatus::Installed);
        expect($agent->commands())->toBe(['tools.vp-inventory']);
    });
});

/** @param list<array{name: string, version: ?string}> $packages
 * @return array{VpInventoryInspector, FakeAgentCommandRunner}
 */
function vp_inventory_inspector(array $packages, bool $complete = true): array
{
    $agent = new FakeAgentCommandRunner()->answer('tools.vp-inventory', ['packages' => $packages, 'scan_complete' => $complete]);
    $versions = new SemverVersionNormalizer;

    return [new VpInventoryInspector(new VpToolManager($versions, $agent), $versions), $agent];
}

function vp_inventory_node(
    string $name = 'vp-inventory-node',
    string $address = '10.8.0.47',
    string $platform = 'macos',
    string $user = 'mini',
    bool $saved = true,
): Node {
    $attributes = [
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => 'arm64',
        'public_ssh_host' => '127.0.0.1',
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ];

    if (! $saved) {
        return new Node($attributes);
    }

    return Node::query()->create($attributes);
}

function vp_inventory_tool(
    Node $node,
    ToolManagerRecord $manager,
    string $package,
    string $version = '1.0.0',
): Tool {
    return $node->tools()->create([
        'tool_manager_id' => $manager->id,
        'package' => $package,
        'status' => ToolStatus::Installed,
        'installed_version' => $version,
    ]);
}
