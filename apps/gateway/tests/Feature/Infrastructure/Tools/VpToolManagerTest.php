<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;

describe(VpToolManager::class, function (): void {
    it('supports Linux nodes independently of roles and identifies itself as VP', function (
        Node $node,
        bool $supported,
    ): void {
        [$manager] = vp_tool_manager();

        expect($manager->name())
            ->toBe(ToolManagerName::Vp)
            ->and($manager->supportsNode($node))
            ->toBe($supported);
    })->with([
        'active app-dev' => [vp_tool_node('linux', [['app-dev', 'active']]), true],
        'provisioning app-prod' => [vp_tool_node('linux', [['app-prod', 'provisioning']]), true],
        'failed app role' => [vp_tool_node('linux', [['app-dev', 'failed']]), true],
        'removing app role' => [vp_tool_node('linux', [['app-prod', 'removing']]), true],
        'gateway only' => [vp_tool_node('linux', [['gateway', 'active']]), true],
        'roleless' => [vp_tool_node('linux', []), true],
        'macOS roleless' => [vp_tool_node('macos', []), true],
        'macOS with an app role' => [vp_tool_node('macos', [['app-dev', 'active']]), true],
        'non-linux app role' => [vp_tool_node('darwin', [['app-dev', 'active']]), false],
    ]);

    it('does not load roles when checking the platform boundary', function (): void {
        [$manager] = vp_tool_manager();
        $node = Node::query()->create([
            'name' => 'vp-macos-node',
            'status' => 'active',
            'platform' => 'darwin',
            'public_ssh_host' => '127.0.0.1',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.10.10',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $node = Node::query()->whereKey($node->id)->sole();

        expect($node->relationLoaded('roles'))->toBeFalse();
        expect($manager->supportsNode($node))->toBeFalse();
        expect($node->relationLoaded('roles'))->toBeFalse();

        $mac = Node::query()->create([
            'name' => 'vp-real-macos-node',
            'status' => 'active',
            'platform' => 'macos',
            'public_ssh_host' => '127.0.0.2',
            'user' => 'mini',
            'wireguard_ip' => '10.44.10.11',
        ]);
        $mac->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $mac = Node::query()->whereKey($mac->id)->sole();

        expect($mac->relationLoaded('roles'))->toBeFalse();
        expect($manager->supportsNode($mac))->toBeTrue();
        expect($mac->relationLoaded('roles'))->toBeFalse();
    });

    it('accepts only strict VP package coordinates', function (string $package, bool $valid): void {
        [$manager] = vp_tool_manager();

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'simple package' => ['typescript', true],
        'scoped package' => ['@openai/codex', true],
        'hyphenated scope' => ['@anthropic-ai/claude-code', true],
        'tilde and underscore' => ['package_name~beta', true],
        'empty' => ['', false],
        'uppercase' => ['TypeScript', false],
        'whitespace' => ['type script', false],
        'newline injection' => ["typescript\nwhoami", false],
        'version tag' => ['typescript@latest', false],
        'version range' => ['typescript^5.0.0', false],
        'url' => ['https://registry.npmjs.org/typescript', false],
        'file' => ['file:../typescript', false],
        'alias' => ['npm:typescript', false],
        'path traversal' => ['../package', false],
        'option syntax' => ['-g', false],
        'missing scoped name' => ['@openai/', false],
        'missing scope sigil' => ['openai/codex', false],
        'double slash' => ['@openai//codex', false],
        'oversized' => [str_repeat('a', times: 215), false],
    ]);

    it('refuses malformed typed agent results', function (string $method, string $command, array $data): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answerMalformed($command, $data);
        expect(fn () => $manager->{$method}(vp_tool_node()))->toThrow(ToolManagerException::class);
    })->with([
        ['managerVersion', 'tool.vp-version', ['version' => []]],
        ['installedInventory', 'tool.vp-inventory', ['packages' => [], 'scan_complete' => null]],
        ['installedInventory', 'tool.vp-inventory', ['packages' => [['name' => 'typescript']], 'scan_complete' => true]],
    ]);

    it('keeps individual Tools verifiable beside ambiguous and unmanageable observations', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tool.vp-inventory', ['packages' => [
            ['name' => 'JSONStream', 'version' => '1.3.5'],
            ['name' => 'typescript', 'version' => '5.7.3'],
            ['name' => 'unreadable', 'version' => null],
            ['name' => 'dup', 'version' => '1.0.0'], ['name' => 'dup', 'version' => '2.0.0'],
            ['name' => 'typescript-extra', 'version' => '5.0.0'],
        ], 'scan_complete' => false]);
        $inventory = $manager->installedInventory(vp_tool_node());
        expect($inventory->versionFor('typescript'))->toBe('5.7.3');
        expect(fn () => $inventory->versionFor('JSONStream'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('absent'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('dup'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('unreadable'))->toThrow(ToolManagerException::class);
        expect($agent->commands())->toBe(['tool.vp-inventory']);
    });

    it('admits older project locks with a newer recipe manager without upgrading', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tool.vp-version', ['version' => 'vp v1.4.0']);

        $manager->ensureVersion(vp_tool_node(), '1.2.0');
        $manager->ensureVersion(vp_tool_node(), '1.2.9');
        $manager->ensureVersion(vp_tool_node(), '1.4.0');
        expect(fn () => $manager->ensureVersion(vp_tool_node(), '1.4.1'))->toThrow(ToolManagerException::class);

        expect($agent->commands())->toBe(['tool.vp-version', 'tool.vp-version', 'tool.vp-version', 'tool.vp-version']);
    });

    it('gates project requirements against the observed recipe manager without upgrading', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tool.vp-version', ['version' => 'vp v0.3.3']);
        $manager->ensureVersion(vp_tool_node(), '0.3.3');
        expect(fn () => $manager->ensureVersion(vp_tool_node(), '0.4.0'))->toThrow(ToolManagerException::class);
        expect($manager->normalizeVersion('v1.2.3'))->toBe('1.2.3');
        expect($manager->normalizeVersion('latest'))->toBeNull();
        expect($agent->commands())->toBe(['tool.vp-version', 'tool.vp-version']);
    });
});

/** @return array{VpToolManager, FakeAgentCommandRunner} */
function vp_tool_manager(): array
{
    $agent = new FakeAgentCommandRunner;

    return [new VpToolManager(new SemverVersionNormalizer, $agent), $agent];
}

/**
 * @param  list<array{0: 'gateway'|'vpn'|'app-dev'|'app-prod', 1: 'provisioning'|'active'|'failed'|'removing'}>  $roles
 */
function vp_tool_node(string $platform = 'linux', array $roles = [['app-dev', 'active']], string $user = 'orbit'): Node
{
    $node = new Node([
        'name' => 'vp-tool-node', 'architecture' => 'x86_64',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => $user,
        'wireguard_ip' => '10.8.0.43',
    ]);

    $node->setRelation(
        'roles',
        collect(array_map(
            static fn (array $role): NodeRole => new NodeRole([
                'role' => RoleName::from($role[0]),
                'status' => LifecycleStatus::from($role[1]),
            ]),
            $roles,
        )),
    );

    return $node;
}
