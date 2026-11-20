<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Actions\Tools\ListToolManagersAction;
use App\Actions\Tools\ScanToolInventoryAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolIntentProjection;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperationException;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Models\Node;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A Gateway-managed Linux Node like Beast; with `$brew`, the agent reports linuxbrew holding the `antigravity-cli` cask. */
function linux_cask_node(bool $brew): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
    ]));
    if ($brew) {
        app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Packages, 'brew', CarbonImmutable::parse('2026-11-20T10:00:00Z'), [
            'scope' => '/home/linuxbrew/.linuxbrew', 'formulae' => [],
            'casks' => [['name' => 'antigravity-cli', 'versions' => ['1.5.13,5999302598852608']]],
        ]);
    }

    return $node;
}

/** @return array<string, ToolInventoryScan> */
function linux_cask_scans(Node $node): array
{
    $scans = [];
    foreach (app(ScanToolInventoryAction::class)->execute($node->id)->managers as $scan) {
        $scans[$scan->manager->value] = $scan;
    }

    return $scans;
}

it('offers casks on a Linux Node whose agent reports Homebrew, and scans them with the build dropped from the version', function (): void {
    $node = linux_cask_node(brew: true);

    expect(app(HomebrewCaskToolManager::class)->supportsNode($node))->toBeTrue()
        ->and(app(StartToolOperationAction::class)->manager($node, 'brew-cask')->name())->toBe(ToolManagerName::BrewCask)
        ->and(app(ListToolManagersAction::class)->execute($node->id)->pluck('name')->all())->toContain('brew-cask');

    $cask = linux_cask_scans($node)['brew-cask'];
    expect($cask->scanState)->toBe(ToolInventoryScanState::Complete)
        ->and($cask->packages)->toHaveCount(1)
        ->and($cask->packages[0]->package)->toBe('antigravity-cli')
        ->and($cask->packages[0]->installedVersion)->toBe('1.5.13');
});

it('keeps casks unsupported on a Linux Node without Homebrew', function (): void {
    $node = linux_cask_node(brew: false);

    expect(app(HomebrewCaskToolManager::class)->supportsNode($node))->toBeFalse()
        ->and(app(ListToolManagersAction::class)->execute($node->id)->pluck('name')->all())->not->toContain('brew-cask')
        ->and(linux_cask_scans($node)['brew-cask']->scanState)->toBe(ToolInventoryScanState::Unsupported)
        ->and(fn () => app(StartToolOperationAction::class)->manager($node, 'brew-cask'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.manager_unsupported'));
});

it('admits a Linux cask adoption and refuses it with the cask artifact block, as on macOS', function (): void {
    $node = linux_cask_node(brew: true);

    expect(fn () => app(StartToolOperationAction::class)->adopt($node->id, 'brew-cask', 'antigravity-cli', '^1.5', null, null))
        ->toThrow(fn (ToolOperationException $error) => expect([$error->errorCode, $error->adoptionBlock])->toBe(['tool.adoption_unsupported', 'unsupported_artifact']));
});

it('reads a cask version without its comma build and leaves formula versions unchanged', function (): void {
    $cask = app(HomebrewCaskToolManager::class);

    expect($cask->normalizeVersion('1.5.13,5999302598852608'))->toBe('1.5.13')
        ->and($cask->normalizeVersion('140.0'))->toBe('140.0.0')
        ->and($cask->normalizeVersion('3.003'))->toBeNull()
        ->and($cask->normalizeVersion(',5999302598852608'))->toBeNull()
        ->and($cask->normalizeVersion("1.5.13,5999302598852608\n"))->toBeNull()
        ->and($cask->normalizeVersion('1.5.13,5999302598852608'.str_repeat('1', 233)))->toBeNull()
        ->and(app(HomebrewToolManager::class)->normalizeVersion('1.5.13,5999302598852608'))->toBeNull();
});

it('installs a Linux cask into the existing Homebrew prefix without a Homebrew setup plan', function (): void {
    $node = linux_cask_node(brew: true);
    $intent = app(ToolManagerIntent::class);

    expect($intent->nativeInput($node, ToolManagerName::BrewCask))->toBeNull()
        ->and($intent->nativeInput($node, ToolManagerName::Brew)['manager'] ?? null)->toBe('homebrew');
});

it('reports a new cask build as applied and the same build as unchanged, both as the public version', function (?string $before, string $after, string $outcome): void {
    $operation = new Operation(['kind' => 'tool.update', 'input' => ['installed_version' => '1.5.13']]);
    $native = ['steps' => ['Assert package state' => ['status' => 'succeeded']],
        'decision' => ['ready' => true, 'outcome' => 'applied', 'installed_version' => '1.5.13', 'installed_coordinate' => $before],
        'observation' => ['installed_version' => '1.5.13', 'installed_coordinate' => $after]];
    $projection = app(ToolIntentProjection::class);

    $result = (fn (): array => $this->nativeResult($operation, ['status' => 'succeeded'], $native))->call($projection);

    expect([$result['outcome'], $result['installed_version']])->toBe([$outcome, '1.5.13']);
})->with([
    'a new build' => ['1.5.13,5999302598852608', '1.5.13,6000000000000000', 'applied'],
    'the same build' => ['1.5.13,5999302598852608', '1.5.13,5999302598852608', 'unchanged'],
]);
