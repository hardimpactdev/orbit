<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
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

    it('rejects an unsupported node before any agent call', function (Closure $operation): void {
        [$manager, $ssh] = vp_tool_manager();
        $node = vp_tool_node('darwin', [['app-dev', 'active']]);

        expect(fn () => $operation($manager, $node))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)
                    ->toBe('node')
                    ->and($exception->result)
                    ->toBeNull()
                    ->and($exception->getMessage())
                    ->not->toContain('gateway');
            });
        expect($ssh->commands())->toBeEmpty();
    })->with([
        'manager version' => [
            static fn (VpToolManager $manager, Node $node): string => $manager->managerVersion($node),
        ],
        'candidate version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'typescript',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->installedVersion($node, 'typescript'),
        ],
        'install' => [static function (VpToolManager $manager, Node $node): void {
            $manager->install($node, 'typescript');
        }],
        'update' => [static function (VpToolManager $manager, Node $node): void {
            $manager->update($node, 'typescript');
        }],
        'removal plan' => [
            static fn (VpToolManager $manager, Node $node) => $manager->planRemoval($node, 'typescript'),
        ],
        'remove' => [static function (VpToolManager $manager, Node $node): void {
            $manager->remove($node, 'typescript');
        }],
    ]);

    it('rejects an invalid package before any agent call', function (Closure $operation): void {
        [$manager, $ssh] = vp_tool_manager();
        $node = vp_tool_node();

        expect(fn () => $operation($manager, $node))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)
                    ->toBe('package')
                    ->and($exception->result)
                    ->toBeNull()
                    ->and($exception->getMessage())
                    ->not->toContain('@openai/');
            });
        expect($ssh->commands())->toBeEmpty();
    })->with([
        'candidate version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                '@openai/',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->installedVersion($node, '@openai/'),
        ],
        'install' => [static function (VpToolManager $manager, Node $node): void {
            $manager->install($node, '@openai/');
        }],
        'update' => [static function (VpToolManager $manager, Node $node): void {
            $manager->update($node, '@openai/');
        }],
        'removal plan' => [static fn (VpToolManager $manager, Node $node) => $manager->planRemoval($node, '@openai/')],
        'remove' => [static function (VpToolManager $manager, Node $node): void {
            $manager->remove($node, '@openai/');
        }],
    ]);

    it('requests one typed command per global operation on Linux and macOS', function (string $platform): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tools.vp-version', ['version' => 'vp v0.3.3'])
            ->answer('tools.vp-candidate', ['version' => '0.150.0'])
            ->answer('tools.vp-inventory', ['packages' => [['name' => '@openai/codex', 'version' => '0.150.0']], 'scan_complete' => true])
            ->answer('tools.vp-install', [])->answer('tools.vp-update', [])
            ->answer('tools.vp-removal-plan', ['packages' => ['@openai/codex']])->answer('tools.vp-remove', []);
        $node = vp_tool_node($platform);
        expect($manager->managerVersion($node))->toBe('vp v0.3.3');
        expect($manager->candidateVersion($node, '@openai/codex', ToolOperation::Install))->toBe('0.150.0');
        expect($manager->installedVersion($node, '@openai/codex'))->toBe('0.150.0');
        $manager->install($node, '@openai/codex');
        $manager->update($node, '@openai/codex');
        expect($manager->planRemoval($node, '@openai/codex')->packages)->toBe(['@openai/codex']);
        $manager->remove($node, '@openai/codex');
        expect($agent->commands())->toBe(['tools.vp-version', 'tools.vp-candidate', 'tools.vp-inventory', 'tools.vp-install', 'tools.vp-update', 'tools.vp-removal-plan', 'tools.vp-remove']);
        expect(array_column($agent->calls, 'input'))->toBe([[], ['package' => '@openai/codex'], [], ['package' => '@openai/codex'], ['package' => '@openai/codex'], ['package' => '@openai/codex'], ['package' => '@openai/codex']]);
    })->with(['linux', 'macos']);

    it('maps scope refusals consistently for reads and mutations', function (string $code, string $step): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->refuse('tools.vp-inventory', $code)->refuse('tools.vp-remove', $code);
        foreach ([fn () => $manager->installedInventory(vp_tool_node()), fn () => $manager->remove(vp_tool_node(), 'typescript')] as $call) {
            expect($call)->toThrow(function (ToolManagerException $error) use ($step): void {
                expect($error->step)->toBe($step);
                expect($error->result)->toBeNull();
            });
        }
        expect($agent->commands())->toBe(['tools.vp-inventory', 'tools.vp-remove']);
    })->with([
        ['tools.vp_scope_absent', 'manager-absent'],
        ['tools.vp_scope_conflict', 'manager-conflict'],
        ['tools.vp_scope_unreadable', 'manager-probe'],
    ]);

    it('maps typed operation refusals without exposing manager output', function (string $method, string $command, string $step): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->refuse($command, 'tools.vp_operation_failed');
        expect(fn () => $manager->{$method}(vp_tool_node(), 'typescript', ToolOperation::Install))
            ->toThrow(function (ToolManagerException $error) use ($step): void {
                expect($error->step)->toBe($step);
                expect($error->result)->toBeNull();
            });
        expect($agent->commands())->toBe([$command]);
    })->with([
        ['managerVersion', 'tools.vp-version', 'manager-version'],
        ['candidateVersion', 'tools.vp-candidate', 'candidate-version'],
        ['installedInventory', 'tools.vp-inventory', 'installed-version'],
        ['install', 'tools.vp-install', 'install'],
        ['update', 'tools.vp-update', 'update'],
        ['planRemoval', 'tools.vp-removal-plan', 'removal-plan'],
        ['remove', 'tools.vp-remove', 'remove'],
    ]);

    it('refuses malformed typed agent results', function (string $method, string $command, array $data): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answerMalformed($command, $data);
        expect(fn () => $manager->{$method}(vp_tool_node(), 'typescript', ToolOperation::Install))->toThrow(ToolManagerException::class);
    })->with([
        ['managerVersion', 'tools.vp-version', ['version' => []]],
        ['candidateVersion', 'tools.vp-candidate', []],
        ['installedInventory', 'tools.vp-inventory', ['packages' => [], 'scan_complete' => null]],
        ['installedInventory', 'tools.vp-inventory', ['packages' => [['name' => 'typescript']], 'scan_complete' => true]],
        ['planRemoval', 'tools.vp-removal-plan', ['packages' => [42]]],
    ]);

    it('keeps individual Tools verifiable beside ambiguous and unmanageable observations', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tools.vp-inventory', ['packages' => [
            ['name' => 'JSONStream', 'version' => '1.3.5'],
            ['name' => 'typescript', 'version' => '5.7.3'],
            ['name' => 'unreadable', 'version' => null],
            ['name' => 'dup', 'version' => '1.0.0'], ['name' => 'dup', 'version' => '2.0.0'],
            ['name' => 'typescript-extra', 'version' => '5.0.0'],
        ], 'scan_complete' => false]);
        $inventory = $manager->installedInventory(vp_tool_node());
        expect($inventory->versionFor('typescript'))->toBe('5.7.3');
        expect($inventory->versionFor('JSONStream'))->toBeNull();
        expect($inventory->versionFor('absent'))->toBeNull();
        expect(fn () => $inventory->versionFor('dup'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('unreadable'))->toThrow(ToolManagerException::class);
        expect($agent->commands())->toBe(['tools.vp-inventory']);
    });

    it('protects pnpm adoption while leaving missing packages absent', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tools.vp-inventory', ['packages' => [['name' => 'pnpm', 'version' => '12.0.0']], 'scan_complete' => true]);
        expect($manager->inspectForAdoption(vp_tool_node(), 'pnpm'))->toEqual(new ToolAdoptionFact('12.0.0', ToolInventoryPackage::BLOCK_PROTECTED));
        expect($manager->inspectForAdoption(vp_tool_node(), 'missing'))->toEqual(new ToolAdoptionFact(null, null));
        expect($agent->commands())->toBe(['tools.vp-inventory', 'tools.vp-inventory']);
    });

    it('materializes Linux from verified pins and only inspects the macOS scope', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tools.materialize-vp', ['binary' => '/opt/orbit/vite-plus/bin/vp', 'changed' => true])
            ->answer('tools.inspect-vp-scope', ['binary' => '/Users/mini/.vite-plus/bin/vp']);
        $manager->materialize(vp_tool_node());
        $manager->materialize(vp_tool_node('macos', [], 'mini'));
        expect($agent->commands())->toBe(['tools.materialize-vp', 'tools.inspect-vp-scope']);
        $input = $agent->calls[0]['input'];
        expect($input)->toMatchArray(['user' => 'orbit', 'architecture' => 'amd64']);
        expect(array_keys($input['tools']))->toEqualCanonicalizing(['node', 'vp-cli', 'vp', 'pnpm']);
        expect(hash('sha256', $input['vp_lock']))->toBe($input['tools']['vp']['lock_sha256']);
        expect($agent->calls[1]['input'])->toBe([]);
    });

    it('gates project requirements against the observed recipe manager without upgrading', function (): void {
        [$manager, $agent] = vp_tool_manager();
        $agent->answer('tools.vp-version', ['version' => 'vp v0.3.3']);
        $manager->ensureVersion(vp_tool_node(), '0.3.3');
        expect(fn () => $manager->ensureVersion(vp_tool_node(), '0.4.0'))->toThrow(ToolManagerException::class);
        expect($manager->normalizeVersion('v1.2.3'))->toBe('1.2.3');
        expect($manager->normalizeVersion('latest'))->toBeNull();
        expect($agent->commands())->toBe(['tools.vp-version', 'tools.vp-version']);
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
