<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
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

    it('passes the active Doctor budget to the agent and restores the default', function (): void {
        $agent = new FakeAgentCommandRunner()->answer('tool.composer-version', ['version' => 'Composer 2.9.0']);
        $budget = new ToolCommandBudget;
        $manager = new ComposerToolManager(new SemverVersionNormalizer, $agent, $budget);
        $budget->limit(15.0, fn (): string => $manager->managerVersion(composer_tool_node()));
        $manager->managerVersion(composer_tool_node());
        expect($agent->deadlines)->toBe([15.0, null]);
    });

    it('rejects malformed typed responses', function (string $method, string $command, array $output): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answerMalformed($command, $output);
        expect(fn () => $manager->$method(composer_tool_node()))->toThrow(ToolManagerException::class);
    })->with([
        ['managerVersion', 'tool.composer-version', ['version' => null]],
        ['managerVersion', 'tool.composer-version', ['version' => 1]],
        ['installedInventory', 'tool.composer-inventory', []],
        ['installedInventory', 'tool.composer-inventory', ['packages' => ['vendor/package' => '1.0.0']]],
        ['installedInventory', 'tool.composer-inventory', ['packages' => [['name' => 1, 'version' => '1.0.0']]]],
        ['installedInventory', 'tool.composer-inventory', ['packages' => [['name' => 'vendor/package']]]],
        ['installedInventory', 'tool.composer-inventory', ['packages' => [['name' => 'vendor/package', 'version' => false]]]],
    ]);

    it('isolates duplicate and unverifiable observations and reads fresh inventory', function (): void {
        [$manager, $agent] = composer_tool_manager();
        $agent->answer('tool.composer-inventory', ['packages' => [
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
        $agent->answer('tool.composer-inventory', ['packages' => []]);
        expect($manager->installedVersion(composer_tool_node(), 'vendor/package'))->toBeNull();
        expect($agent->commands())->toBe(['tool.composer-inventory', 'tool.composer-inventory']);
    });

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
