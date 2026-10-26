<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Tools\HomebrewCommand;
use App\Infrastructure\Tools\HomebrewInventoryInspector;
use App\Models\Node;
use App\Models\Tool;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

describe(HomebrewInventoryInspector::class, function (): void {
    it('joins typed observations only to matching Tool intent on this Node', function (): void {
        $node = homebrew_inventory_node();
        $other = homebrew_inventory_node('other');
        $brew = $node->toolManagers()->create(['name' => 'brew', 'status' => LifecycleStatus::Active]);
        $cask = $node->toolManagers()->create(['name' => 'brew-cask', 'status' => LifecycleStatus::Active]);
        $formula = $node->tools()->create(['tool_manager_id' => $brew->id, 'package' => 'docker', 'status' => ToolStatus::Installed]);
        $caskTool = $node->tools()->create(['tool_manager_id' => $cask->id, 'package' => 'docker', 'status' => ToolStatus::Failed]);
        $otherManager = $other->toolManagers()->create(['name' => 'brew', 'status' => LifecycleStatus::Active]);
        $other->tools()->create(['tool_manager_id' => $otherManager->id, 'package' => 'ripgrep', 'status' => ToolStatus::Installed]);
        [$inspector, $agent] = homebrew_inventory_inspector([
            'formulae' => ['state' => 'complete', 'packages' => [
                homebrew_observation('ripgrep', 'v14.1'), homebrew_observation('docker', '28.0.1'),
                homebrew_observation('openssl@3', '3.4.0', 'dependency', true),
                homebrew_observation('wireguard-tools', null, 'protected'),
                homebrew_observation('unreadable', null, 'version_unreadable'),
            ]],
            'casks' => ['state' => 'complete', 'packages' => [homebrew_observation('docker', '4.39.0', 'authorization_required')]],
        ]);
        $before = Tool::query()->count();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $scans = $inspector->inspect($node);
        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[0]->packages[0]->toolId)->toBe($formula->id)
            ->and($scans[0]->packages[1]->dependency)->toBeTrue()
            ->and($scans[0]->packages[2]->registered)->toBeFalse()
            ->and($scans[0]->packages[2]->installedVersion)->toBe('14.1.0')
            ->and($scans[0]->packages[3]->adoptionBlock)->toBe('version_unreadable')
            ->and($scans[0]->packages[4]->adoptionBlock)->toBe('protected')
            ->and($scans[1]->packages[0]->toolId)->toBe($caskTool->id)
            ->and($scans[1]->packages[0]->adoptionBlock)->toBe('authorization_required')
            ->and(Tool::query()->count())->toBe($before);
        foreach (DB::getQueryLog() as $query) {
            expect(strtolower($query['query']))->not->toMatch('/\b(insert|update|delete|replace)\b/');
        }
        expect($agent->calls)->toBe([['node' => $node->name, 'command' => 'tool.homebrew-scan', 'input' => ['architecture' => 'arm64']]]);
    });

    it('preserves independent scan results and keeps Linux casks unsupported', function (string $platform): void {
        [$inspector, $agent] = homebrew_inventory_inspector([
            'formulae' => ['state' => 'incomplete', 'packages' => []],
            'casks' => ['state' => 'complete', 'packages' => [homebrew_observation('font-hack', '2.0')]],
        ]);
        $scans = $inspector->inspect(homebrew_inventory_node(platform: $platform));
        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe($platform === 'macos' ? ToolInventoryScanState::Complete : ToolInventoryScanState::Unsupported);
        expect($agent->calls[0]['input'])->toBe($platform === 'macos' ? ['architecture' => 'arm64'] : []);
    })->with(['linux', 'macos']);

    it('maps native scope refusal to scan state without creating tools', function (string $code, ToolInventoryScanState $state): void {
        [$inspector, $agent] = homebrew_inventory_inspector([]);
        $agent->refuse('tool.homebrew-scan', $code);
        $scans = $inspector->inspect(homebrew_inventory_node());
        expect($scans[0]->scanState)->toBe($state)->and($scans[1]->scanState)->toBe($state)
            ->and($scans[0]->packages)->toBe([])->and($scans[1]->packages)->toBe([])->and(Tool::query()->count())->toBe(0);
    })->with([
        ['tool.homebrew_scope_absent', ToolInventoryScanState::Absent],
        ['tool.homebrew_scope_conflict', ToolInventoryScanState::Conflicting],
        ['tool.homebrew_scope_unreadable', ToolInventoryScanState::Incomplete],
        ['tool.homebrew_bottle_unavailable', ToolInventoryScanState::Incomplete],
        ['tool.homebrew_operation_failed', ToolInventoryScanState::Incomplete],
    ]);

    it('refuses malformed typed observations without discarding another successful manager', function (mixed $data): void {
        [$inspector] = homebrew_inventory_inspector(['formulae' => $data, 'casks' => ['state' => 'complete', 'packages' => []]]);
        $scans = $inspector->inspect(homebrew_inventory_node());
        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete);
    })->with([
        [null], [[]], [['state' => 'complete', 'packages' => 'invalid']],
        [['state' => 'complete', 'packages' => [['name' => 'jq']]]],
        [['state' => 'complete', 'packages' => [homebrew_observation('jq', '1.7'), homebrew_observation('jq', '1.7')]]],
        [['state' => 'complete', 'packages' => [homebrew_observation('jq', '1.7', 'unknown')]]],
    ]);

    it('does not inspect an unsupported or disconnected Node', function (): void {
        [$inspector, $agent] = homebrew_inventory_inspector([]);
        $node = homebrew_inventory_node(platform: 'darwin');
        expect($inspector->inspect($node)[0]->scanState)->toBe(ToolInventoryScanState::Unsupported);
        $node->platform = 'macos';
        $node->wireguard_ip = null;
        expect($inspector->inspect($node)[0]->scanState)->toBe(ToolInventoryScanState::Incomplete);
        expect($agent->calls)->toBe([]);
    });
});

/** @return array{HomebrewInventoryInspector, FakeAgentCommandRunner} */
function homebrew_inventory_inspector(array $data): array
{
    // Many cases send scan shapes the agent never returns, to test the inspector's own guards.
    $agent = new FakeAgentCommandRunner()->answerMalformed('tool.homebrew-scan', $data);

    return [new HomebrewInventoryInspector(new SemverVersionNormalizer, new HomebrewCommand($agent)), $agent];
}

function homebrew_inventory_node(string $name = 'mini', string $platform = 'macos'): Node
{
    return Node::create(['name' => $name, 'platform' => $platform, 'architecture' => 'arm64', 'user' => 'mini', 'status' => LifecycleStatus::Active, 'wireguard_ip' => $name === 'mini' ? '10.8.0.45' : '10.8.0.46', 'public_ssh_host' => '127.0.0.1']);
}

/** @return array{name:string, installed_version:?string, dependency:bool, block:?string} */
function homebrew_observation(string $name, ?string $version, ?string $block = null, bool $dependency = false): array
{
    return ['name' => $name, 'installed_version' => $version, 'dependency' => $dependency, 'block' => $block];
}
