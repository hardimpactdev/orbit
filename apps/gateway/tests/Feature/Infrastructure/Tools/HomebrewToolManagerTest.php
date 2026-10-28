<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(HomebrewToolManager::class, function (): void {
    it('is the role-independent Linux Homebrew manager', function (
        string $platform,
        ?RoleName $role,
        bool $supported,
    ): void {
        [$manager] = homebrew_tool_manager([]);

        expect($manager)
            ->toBeInstanceOf(ToolManager::class)
            ->and($manager->name())
            ->toBe(ToolManagerName::Brew)
            ->and($manager->supportsNode(homebrew_tool_node($platform, $role)))
            ->toBe($supported);
    })->with([
        'app-dev' => ['linux', RoleName::AppDev, true],
        'app-prod' => ['linux', RoleName::AppProd, true],
        'gateway' => ['linux', RoleName::Gateway, true],
        'roleless' => ['linux', null, true],
        'macOS roleless' => ['macos', null, true],
        'macOS with a role' => ['macos', RoleName::AppDev, true],
        'non-Linux' => ['darwin', RoleName::AppDev, false],
    ]);

    it('accepts only unqualified lowercase formula names', function (string $package, bool $valid): void {
        [$manager] = homebrew_tool_manager([]);

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'simple' => ['ripgrep', true],
        'versioned formula' => ['php@8.5', true],
        'punctuation' => ['libc++_tool.1', true],
        'trailing pluses' => ['libsigc++', true],
        'single trailing plus' => ['gtk+', true],
        'plus before the end' => ['mysql-connector-c++', true],
        'trailing hyphen' => ['gtk-', false],
        'empty' => ['', false],
        'uppercase' => ['Ripgrep', false],
        'tap' => ['homebrew/core/ripgrep', false],
        'other tap' => ['example/tap/ripgrep', false],
        'cask option' => ['--cask', false],
        'URL' => ['https://example.com/ripgrep.rb', false],
        'local formula' => ['./ripgrep.rb', false],
        'Git ref' => ['ripgrep#main', false],
        'whitespace' => ['ripgrep service', false],
        'oversized' => [str_repeat('a', times: 256), false],
    ]);

    it('maps native inventory refusals instead of reporting absent packages', function (string $code): void {
        [$manager, $ssh, $agent] = homebrew_tool_manager([]);
        $agent->refuse('tool.homebrew-formula-inventory', $code);
        expect(fn () => $manager->installedVersion(homebrew_tool_node(), 'ripgrep'))
            ->toThrow(fn (ToolManagerException $error) => expect($error->step)->toBe('installed-version'));
        expect($ssh->commands)->toBe([]);
    })->with(['tool.homebrew_operation_failed', 'tool.homebrew_output_invalid']);

    it('keeps absent candidates separate from failed probes', function (): void {
        [$manager, , $agent] = homebrew_tool_manager([]);
        $agent->answer('tool.homebrew-formula-candidate', ['version' => null]);
        expect($manager->candidateVersion(homebrew_tool_node(), 'missing', ToolOperation::Install))->toBeNull();
        $agent->refuse('tool.homebrew-formula-candidate', 'tool.homebrew_operation_failed');
        expect(fn () => $manager->candidateVersion(homebrew_tool_node(), 'missing', ToolOperation::Install))->toThrow(ToolManagerException::class);
    });

    it('preserves native adoption facts without executing Homebrew', function (?string $version, ?string $block): void {
        [$manager, $ssh, $agent] = homebrew_tool_manager([]);
        $agent->answer('tool.homebrew-formula-adoption', ['installed_version' => $version, 'block' => $block]);
        expect($manager->inspectForAdoption(homebrew_tool_node('macos', architecture: 'arm64'), 'ripgrep'))
            ->toEqual(new ToolAdoptionFact($version, $block));
        expect($agent->calls)->toBe([['node' => 'homebrew-tool-node', 'command' => 'tool.homebrew-formula-adoption', 'input' => ['package' => 'ripgrep', 'architecture' => 'arm64']]])
            ->and($ssh->commands)->toBe([]);
    })->with([
        ['14.1.1', null], [null, null], ['14.1.1', 'dependency'], ['14.1.1', 'protected'],
        ['14.1.1', 'unsupported_source'], [null, 'version_unreadable'],
        ['14.1.1', 'unsupported_artifact'], ['14.1.1', 'bottle_unavailable'],
    ]);

    it('normalizes versions in the Gateway and keeps the native manager version', function (): void {
        [$manager, , $agent] = homebrew_tool_manager([]);
        $agent->answer('tool.homebrew-version', ['version' => 'Homebrew 4.6.15']);
        expect($manager->managerVersion(homebrew_tool_node('macos')))->toBe('Homebrew 4.6.15')
            ->and($manager->normalizeVersion('v0.9'))->toBe('0.9.0')
            ->and($manager->normalizeVersion('head'))->toBeNull();
    });
});

/** @return array{HomebrewToolManager, ToolManagerFakeSshExecutor, FakeAgentCommandRunner} */
function homebrew_tool_manager(array $results): array
{
    $agent = new FakeAgentCommandRunner()
        ->answer('tool.inspect-homebrew-scope', ['prefix' => '/opt/homebrew', 'home' => '/Users/mini'])
        ->answer('tool.homebrew-version', ['version' => 'Homebrew 7.0.0'])
        ->answer('tool.homebrew-formula-inventory', ['packages' => []]);
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [new HomebrewToolManager(new SemverVersionNormalizer, $agent), $ssh, $agent];
}

function homebrew_tool_node(
    string $platform = 'linux',
    ?RoleName $role = RoleName::AppDev,
    ?string $architecture = null,
    string $user = 'orbit',
): Node {
    $node = new Node([
        'name' => 'homebrew-tool-node',
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $architecture,
        'public_ssh_host' => '127.0.0.1',
        'user' => $user,
        'wireguard_ip' => '10.8.0.44',
    ]);
    $roles = $role === null
        ? []
        : [new NodeRole([
            'role' => $role,
            'status' => LifecycleStatus::Active,
        ])];
    $node->setRelation('roles', collect($roles));

    return $node;
}

it('uses a fresh typed formula inventory on each read', function (string $platform): void {
    [$manager, $ssh, $agent] = homebrew_tool_manager([]);
    $inventories = [
        ['packages' => [
            ['name' => 'jq', 'versions' => ['1.7.1'], 'unverifiable' => false],
            ['name' => 'ambiguous', 'versions' => ['1.0', '2.0'], 'unverifiable' => false],
            ['name' => 'unreadable', 'versions' => [], 'unverifiable' => true],
        ]],
        ['packages' => [['name' => 'jq', 'versions' => ['1.8.0'], 'unverifiable' => false]]],
    ];
    $agent->answer('tool.homebrew-formula-inventory', function () use (&$inventories): array {
        return array_shift($inventories);
    });
    $node = homebrew_tool_node($platform, architecture: 'arm64');
    $first = $manager->installedInventory($node);
    expect($first->versionFor('jq'))->toBe('1.7.1')->and($first->versionFor('absent'))->toBeNull();
    expect(fn () => $first->versionFor('ambiguous'))->toThrow(ToolManagerException::class);
    expect(fn () => $first->versionFor('unreadable'))->toThrow(ToolManagerException::class);
    expect($manager->installedInventory($node)->versionFor('jq'))->toBe('1.8.0');
    expect($agent->commands())->toBe(['tool.homebrew-formula-inventory', 'tool.homebrew-formula-inventory'])
        ->and($ssh->commands)->toBe([]);
})->with(['linux', 'macos']);

it('refuses malformed typed inventory observations', function (array $data): void {
    [$manager, $ssh, $agent] = homebrew_tool_manager([]);
    $agent->answerMalformed('tool.homebrew-formula-inventory', $data);
    expect(fn () => $manager->installedInventory(homebrew_tool_node()))->toThrow(ToolManagerException::class);
    expect($ssh->commands)->toBe([]);
})->with([
    'missing inventory' => [[]],
    'non-list inventory' => [['packages' => ['jq' => []]]],
    'missing fields' => [['packages' => [['name' => 'jq']]]],
    'invalid version' => [['packages' => [['name' => 'jq', 'versions' => [false], 'unverifiable' => false]]]],
]);
