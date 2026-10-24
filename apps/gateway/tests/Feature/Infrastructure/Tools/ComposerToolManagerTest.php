<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\FakeAgentCommandRunner;

describe(ComposerToolManager::class, function (): void {
    it('implements the Composer tool manager adapter', function (): void {
        [$manager] = composer_tool_manager();

        expect($manager)->toBeInstanceOf(ToolManager::class);
        expect($manager->name())->toBe(ToolManagerName::Composer);
    });

    it('supports Linux nodes independently of node and role lifecycle', function (
        string $platform,
        LifecycleStatus $nodeStatus,
        ?RoleName $role,
        ?LifecycleStatus $roleStatus,
        bool $supported,
    ): void {
        [$manager] = composer_tool_manager();

        expect($manager->supportsNode(composer_tool_node($platform, $nodeStatus, $role, $roleStatus)))
            ->toBe($supported);
    })->with([
        'active app-dev' => ['linux', LifecycleStatus::Active, RoleName::AppDev, LifecycleStatus::Active, true],
        'provisioning app-prod' => [
            'linux',
            LifecycleStatus::Provisioning,
            RoleName::AppProd,
            LifecycleStatus::Provisioning,
            true,
        ],
        'provisioning role on active node' => [
            'linux',
            LifecycleStatus::Active,
            RoleName::AppDev,
            LifecycleStatus::Provisioning,
            true,
        ],
        'gateway role' => ['linux', LifecycleStatus::Active, RoleName::Gateway, LifecycleStatus::Active, true],
        'failed role' => ['linux', LifecycleStatus::Active, RoleName::AppDev, LifecycleStatus::Failed, true],
        'removing role' => ['linux', LifecycleStatus::Active, RoleName::AppProd, LifecycleStatus::Removing, true],
        'non-Linux node' => ['darwin', LifecycleStatus::Active, RoleName::AppDev, LifecycleStatus::Active, false],
        'missing role' => ['linux', LifecycleStatus::Active, null, null, true],
    ]);

    it('accepts only one lowercase Composer package coordinate of at most 255 bytes', function (
        string $package,
        bool $valid,
    ): void {
        [$manager] = composer_tool_manager();

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'normal package' => ['laravel/installer', true],
        'single character segments' => ['a/b', true],
        'allowed punctuation' => ['vendor-name/package_name.php', true],
        '255 bytes' => [str_repeat('v', times: 127).'/'.str_repeat('p', times: 127), true],
        'empty' => ['', false],
        'missing package' => ['laravel', false],
        'extra segment' => ['laravel/framework/installer', false],
        'uppercase' => ['Laravel/installer', false],
        'leading punctuation' => ['.laravel/installer', false],
        'trailing punctuation' => ['laravel/installer-', false],
        'leading option' => ['--working-dir/package', false],
        'constraint' => ['laravel/installer:^5.0', false],
        'stability flag' => ['laravel/installer@dev', false],
        'URL' => ['https://example.com/package', false],
        'whitespace' => ['laravel/installer package', false],
        'traversal prefix' => ['../laravel/installer', false],
        'traversal segment' => ['laravel/../installer', false],
        'wildcard' => ['laravel/*', false],
        'oversized' => [str_repeat('v', times: 128).'/'.str_repeat('p', times: 127), false],
    ]);

    it('materializes Composer through the typed command with its enrolled account', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer('tools.materialize-composer', []);
        $manager->materialize(composer_tool_node(role: null, roleStatus: null));
        expect($agent->commands())->toBe(['tools.materialize-composer']);
        expect($agent->calls[0]['input'])->toBe(['user' => 'orbit']);
    });

    it('rejects an unsupported node before remote I/O', function (Closure $operation): void {
        [$manager, $agent] = composer_tool_manager();
        $node = composer_tool_node(platform: 'darwin', role: RoleName::AppDev);

        expect(fn () => $operation($manager, $node))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('node');
                expect($exception->result)->toBeNull();
            });
        expect($agent->commands())->toBeEmpty();
    })->with([
        'manager version' => [
            static fn (ComposerToolManager $manager, Node $node): string => $manager->managerVersion($node),
        ],
        'candidate version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'laravel/installer',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->installedVersion(
                $node,
                'laravel/installer',
            ),
        ],
        'installed inventory' => [
            static fn (ComposerToolManager $manager, Node $node): InstalledToolInventory => $manager->installedInventory(
                $node,
            ),
        ],
        'install' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->install($node, 'laravel/installer'),
        ],
        'update' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->update($node, 'laravel/installer'),
        ],
        'removal plan' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->planRemoval($node, 'laravel/installer'),
        ],
        'remove' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->remove($node, 'laravel/installer'),
        ],
    ]);

    it('rejects an invalid package before remote I/O', function (Closure $operation): void {
        [$manager, $agent] = composer_tool_manager();

        expect(fn () => $operation($manager, composer_tool_node()))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('package');
                expect($exception->result)->toBeNull();
                expect($exception->getMessage())->not->toContain('https://');
            });
        expect($agent->commands())->toBeEmpty();
    })->with([
        'candidate version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'https://example.com/package',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->installedVersion(
                $node,
                'https://example.com/package',
            ),
        ],
        'install' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->install(
                $node,
                'https://example.com/package',
            ),
        ],
        'update' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->update(
                $node,
                'https://example.com/package',
            ),
        ],
        'removal plan' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->planRemoval(
                $node,
                'https://example.com/package',
            ),
        ],
        'remove' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->remove(
                $node,
                'https://example.com/package',
            ),
        ],
    ]);

    it('uses typed commands for the complete lifecycle ', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer('tools.composer-version', ['version' => 'Composer version 2.8.12']);
        $agent->answer('tools.composer-inventory', ['packages' => [['name' => 'laravel/installer', 'version' => 'v5.16.0']]]);
        $agent->answer('tools.composer-candidate', ['version' => 'v5.17.0']);
        $agent->answer('tools.composer-install', []);
        $agent->answer('tools.composer-update', []);
        $agent->answer('tools.composer-removal-plan', ['packages' => ['laravel/installer']]);
        $agent->answer('tools.composer-remove', []);
        $node = composer_tool_node();

        expect($manager->managerVersion($node))->toBe('Composer version 2.8.12');
        expect($manager->installedVersion($node, 'laravel/installer'))->toBe('v5.16.0');
        expect($manager->candidateVersion($node, 'laravel/installer', ToolOperation::Install))->toBe('v5.17.0');
        expect($manager->candidateVersion($node, 'laravel/installer', ToolOperation::Update))->toBe('v5.17.0');
        $manager->install($node, 'laravel/installer');
        $manager->update($node, 'laravel/installer');
        expect($manager->planRemoval($node, 'laravel/installer')->removesOnly('laravel/installer'))->toBeTrue();
        $manager->remove($node, 'laravel/installer');

        expect(array_map(static fn (array $call): array => [$call['command'], $call['input']], $agent->calls))->toBe([
            ['tools.composer-version', []],
            ['tools.composer-inventory', []],
            ['tools.composer-candidate', ['package' => 'laravel/installer', 'operation' => 'install']],
            ['tools.composer-candidate', ['package' => 'laravel/installer', 'operation' => 'update']],
            ['tools.composer-install', ['package' => 'laravel/installer']],
            ['tools.composer-update', ['package' => 'laravel/installer']],
            ['tools.composer-removal-plan', ['package' => 'laravel/installer']],
            ['tools.composer-remove', ['package' => 'laravel/installer']],
        ]);
        expect($manager->normalizeVersion('v5.17.0'))->toBe('5.17.0');
        expect($manager->normalizeVersion('dev-main'))->toBeNull();
    });

    it('rejects candidate selection for removal and adoption before I/O', function (ToolOperation $operation): void {
        [$manager, $agent] = composer_tool_manager();
        expect(fn () => $manager->candidateVersion(composer_tool_node(), 'vendor/package', $operation))->toThrow(ToolManagerException::class);
        expect($agent->commands())->toBe([]);
    })->with([ToolOperation::Remove, ToolOperation::Adopt]);

    it('passes the active Doctor budget to the agent and restores the default', function (): void {
        $agent = new FakeAgentCommandRunner()->answer('tools.composer-version', ['version' => 'Composer 2.9.0']);
        $budget = new ToolCommandBudget;
        $manager = new ComposerToolManager(new SemverVersionNormalizer, $agent, $budget);
        $budget->limit(15.0, fn (): string => $manager->managerVersion(composer_tool_node()));
        $manager->managerVersion(composer_tool_node());
        expect($agent->deadlines)->toBe([15.0, null]);
    });

    it('retains a missing candidate as null', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer('tools.composer-candidate', ['version' => null]);
        expect($manager->candidateVersion(composer_tool_node(), 'vendor/package', ToolOperation::Install))->toBeNull();
    });

    it('maps agent refusals and transport failures to the operation without raw diagnostics', function (string $method, string $command, string $step, bool $transport): void {
        [$manager, $agent] = composer_tool_manager();
        if ($transport) {
            $agent->answer($command, static fn (): array => throw new AgentRunnerFailure($command, 'transport', 'secret transport diagnostic'));
        } else {
            $agent->refuse($command, 'tools.composer_operation_failed');
        }
        expect(fn () => $manager->$method(composer_tool_node(), 'vendor/package', ToolOperation::Install))->toThrow(function (ToolManagerException $error) use ($step): void {
            expect($error->step)->toBe($step);
            expect($error->result)->toBeNull();
            expect($error->getMessage())->not->toContain('secret');
        });
    })->with([
        ['managerVersion', 'tools.composer-version', 'manager-version'],
        ['candidateVersion', 'tools.composer-candidate', 'candidate-version'],
        ['installedInventory', 'tools.composer-inventory', 'installed-version'],
        ['install', 'tools.composer-install', 'install'],
        ['update', 'tools.composer-update', 'update'],
        ['planRemoval', 'tools.composer-removal-plan', 'removal-plan'],
        ['remove', 'tools.composer-remove', 'remove'],
    ])->with([false, true]);

    it('rejects malformed typed responses', function (string $method, string $command, array $output): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer($command, $output);
        expect(fn () => $manager->$method(composer_tool_node(), 'vendor/package', ToolOperation::Install))->toThrow(ToolManagerException::class);
    })->with([
        ['managerVersion', 'tools.composer-version', ['version' => null]],
        ['managerVersion', 'tools.composer-version', ['version' => 1]],
        ['candidateVersion', 'tools.composer-candidate', []],
        ['installedInventory', 'tools.composer-inventory', []],
        ['installedInventory', 'tools.composer-inventory', ['packages' => ['vendor/package' => '1.0.0']]],
        ['installedInventory', 'tools.composer-inventory', ['packages' => [['name' => 1, 'version' => '1.0.0']]]],
        ['installedInventory', 'tools.composer-inventory', ['packages' => [['name' => 'vendor/package']]]],
        ['installedInventory', 'tools.composer-inventory', ['packages' => [['name' => 'vendor/package', 'version' => false]]]],
        ['planRemoval', 'tools.composer-removal-plan', ['packages' => ['vendor/other']]],
    ]);

    it('isolates duplicate and unverifiable observations and reads fresh inventory', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer('tools.composer-inventory', ['packages' => [
            ['name' => 'vendor/package', 'version' => 'v1.0.0'],
            ['name' => 'vendor/duplicate', 'version' => '1.0.0'],
            ['name' => 'vendor/duplicate', 'version' => '1.0.0'],
            ['name' => 'vendor/unreadable', 'version' => null],
            ['name' => 'invalid;name', 'version' => '1.0.0'],
        ]]);
        $inventory = $manager->installedInventory(composer_tool_node());
        expect($inventory->versionFor('vendor/package'))->toBe('v1.0.0');
        expect($inventory->versionFor('vendor/absent'))->toBeNull();
        expect(fn () => $inventory->versionFor('vendor/duplicate'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('vendor/unreadable'))->toThrow(ToolManagerException::class);
        $agent->answer('tools.composer-inventory', ['packages' => []]);
        expect($manager->installedVersion(composer_tool_node(), 'vendor/package'))->toBeNull();
        expect($agent->commands())->toBe(['tools.composer-inventory', 'tools.composer-inventory']);
    });

    it('uses typed root observations for adoption and refuses dependencies', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer('tools.inspect-composer-scope', ['roots' => ['php', 'ext-json', 'legacy/Uppercase', 'laravel/pint']]);
        $agent->answer('tools.composer-inventory', ['packages' => [['name' => 'laravel/pint', 'version' => '1.20.0'], ['name' => 'symfony/console', 'version' => '7.1.0']]]);
        expect($manager->inspectForAdoption(composer_tool_node(role: null), 'laravel/pint'))->toEqual(new ToolAdoptionFact('1.20.0', null));
        expect($manager->inspectForAdoption(composer_tool_node(role: null), 'symfony/console'))->toEqual(new ToolAdoptionFact('7.1.0', ToolInventoryPackage::BLOCK_DEPENDENCY));
        expect($manager->inspectForAdoption(composer_tool_node(role: null), 'vendor/absent'))->toEqual(new ToolAdoptionFact(null, null));
        expect($agent->commands())->toBe(array_merge(...array_fill(0, 3, ['tools.inspect-composer-scope', 'tools.composer-inventory'])));
    });
    it('retains the materialization failure step for an ownership conflict', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->refuse('tools.materialize-composer', 'tools.composer_scope_conflict');
        expect(fn () => $manager->materialize(composer_tool_node()))->toThrow(function (ToolManagerException $error): void {
            expect($error->step)->toBe('materialize');
        });
        expect($agent->commands())->toHaveCount(1);
    });

    it('maps Composer scope refusals before reading package inventory', function (string $code, string $step): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->refuse('tools.inspect-composer-scope', $code);
        expect(fn () => $manager->inspectForAdoption(composer_tool_node(), 'vendor/package'))->toThrow(function (ToolManagerException $error) use ($step): void {
            expect($error->step)->toBe($step);
            expect($error->result)->toBeNull();
        });
        expect($agent->commands())->toHaveCount(1);
    })->with([['tools.composer_scope_absent', 'manager-absent'], ['tools.composer_scope_conflict', 'manager-conflict'], ['tools.composer_scope_unreadable', 'manager-probe']]);

});

/** @return array{ComposerToolManager, FakeAgentCommandRunner} */
function composer_tool_manager(): array
{
    $agent = new FakeAgentCommandRunner;

    return [new ComposerToolManager(new SemverVersionNormalizer, $agent), $agent];
}

function composer_tool_node(
    string $platform = 'linux',
    LifecycleStatus $status = LifecycleStatus::Active,
    ?RoleName $role = RoleName::AppDev,
    ?LifecycleStatus $roleStatus = LifecycleStatus::Active,
): Node {
    $node = new Node([
        'name' => 'composer-tool-node',
        'status' => $status,
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.45',
    ]);
    $roles = [];

    if ($role !== null && $roleStatus !== null) {
        $roles[] = new NodeRole([
            'role' => $role,
            'status' => $roleStatus,
        ]);
    }

    $node->setRelation('roles', new EloquentCollection($roles));

    return $node;
}
