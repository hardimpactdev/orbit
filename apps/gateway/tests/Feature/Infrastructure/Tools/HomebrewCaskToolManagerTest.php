<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Models\Node;
use App\Models\Tool;
use Tests\Support\FakeAgentCommandRunner;

describe(HomebrewCaskToolManager::class, function (): void {
    it('is a macOS-only manager and rejects taps, urls, local files, and options', function (string $package, bool $valid): void {
        [$manager] = cask_manager();

        expect($manager->name())->toBe(ToolManagerName::BrewCask)
            ->and($manager->supportsNode(cask_node('linux')))->toBeFalse()
            ->and($manager->supportsNode(cask_node()))->toBeTrue()
            ->and($manager->validatePackage($package))->toBe($valid);
    })->with([
        'token' => ['font-hack', true],
        'versioned token' => ['firefox@esr', true],
        'trailing plus' => ['logi-options+', true],
        'repeated trailing plus' => ['starnet++', true],
        'trailing hyphen' => ['logi-options-', false],
        'empty' => ['', false],
        'uppercase' => ['Font-Hack', false],
        'tap' => ['homebrew/cask/font-hack', false],
        'url' => ['https://example.com/font-hack.rb', false],
        'local file' => ['./font-hack.rb', false],
        'absolute file' => ['/tmp/font-hack.rb', false],
        'zap option' => ['--zap', false],
        'cask option' => ['--cask', false],
    ]);

    it('stores a formula and a cask with the same name as different tools', function (): void {
        $node = cask_saved_node();
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew,
            'status' => LifecycleStatus::Active,
        ]);
        $cask = $node->toolManagers()->create([
            'name' => ToolManagerName::BrewCask,
            'status' => LifecycleStatus::Active,
        ]);
        $formula = $node->tools()->create([
            'tool_manager_id' => $brew->id,
            'package' => 'docker',
            'status' => ToolStatus::Installed,
            'installed_version' => '28.0.0',
        ]);
        $caskTool = $node->tools()->create([
            'tool_manager_id' => $cask->id,
            'package' => 'docker',
            'status' => ToolStatus::Installed,
            'installed_version' => '4.34.0',
        ]);

        expect($brew->id)->not->toBe($cask->id)
            ->and($formula->id)->not->toBe($caskTool->id)
            ->and(Tool::query()->where('package', 'docker')->count())->toBe(2);
    });

    it('reads the native manager version and preserves absence and ambiguous inventories', function (): void {
        [$manager, $agent] = cask_manager();
        $agent->answer('tool.homebrew-cask-candidate', ['version' => null]);
        expect($manager->managerVersion(cask_node()))->toBe('Homebrew 4.6.15')
            ->and($manager->candidateVersion(cask_node(), 'missing', ToolOperation::Install))->toBeNull();
        $agent->answer('tool.homebrew-cask-inventory', ['packages' => [
            ['name' => 'font-hack', 'versions' => ['3.003'], 'unverifiable' => false],
            ['name' => 'ambiguous', 'versions' => ['1.0', '2.0'], 'unverifiable' => false],
            ['name' => 'unreadable', 'versions' => [], 'unverifiable' => true],
        ]]);
        $inventory = $manager->installedInventory(cask_node());
        expect($inventory->versionFor('font-hack'))->toBe('3.003')->and($inventory->versionFor('missing'))->toBeNull();
        expect(fn () => $inventory->versionFor('ambiguous'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('unreadable'))->toThrow(ToolManagerException::class);
        $agent->refuse('tool.homebrew-cask-inventory', 'tool.homebrew_output_invalid');
        expect(fn () => $manager->installedVersion(cask_node(), 'font-hack'))->toThrow(ToolManagerException::class);
    });

    it('returns native adoption facts without creating Tool rows', function (?string $version, ?string $block): void {
        [$manager, $agent] = cask_manager();
        $agent->answer('tool.homebrew-cask-adoption', ['installed_version' => $version, 'block' => $block]);
        expect($manager->inspectForAdoption(cask_node(), 'font-hack'))->toEqual(new ToolAdoptionFact($version, $block));
        expect(Tool::query()->count())->toBe(0);
    })->with([['3.003', null], [null, null], ['3.003', 'authorization_required'], ['3.003', 'unsupported_source'], ['3.003', 'unsupported_artifact'], ['3.003', 'version_unreadable']]);

});

/** @return array{HomebrewCaskToolManager, FakeAgentCommandRunner} */
function cask_manager(): array
{
    $agent = new FakeAgentCommandRunner()->answer('tool.inspect-homebrew-scope', ['prefix' => '/opt/homebrew', 'home' => '/Users/mini'])
        ->answer('tool.homebrew-version', ['version' => 'Homebrew 4.6.15'])->answer('tool.homebrew-cask-inventory', ['packages' => []]);

    return [new HomebrewCaskToolManager(new SemverVersionNormalizer, $agent), $agent];
}

function cask_node(string $platform = 'macos', string $architecture = 'arm64'): Node
{
    return new Node([
        'name' => 'cask-node',
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $architecture,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'mini',
        'wireguard_ip' => '10.8.0.45',
    ]);
}

function cask_saved_node(): Node
{
    return Node::query()->create([
        'name' => 'cask-saved-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'mini',
        'wireguard_ip' => '10.8.0.46',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
}
