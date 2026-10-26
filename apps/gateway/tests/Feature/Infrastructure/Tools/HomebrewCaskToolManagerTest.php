<?php

declare(strict_types=1);

use App\Actions\Tools\InstallToolAction;
use App\Actions\Tools\RemoveToolAction;
use App\Actions\Tools\UpdateToolAction;
use App\Data\Tools\InstallToolData;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Models\Node;
use App\Models\Tool;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ImmediateToolOperationLock;

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

    it('sends typed cask operations with the stored architecture', function (): void {
        [$manager, $agent] = cask_manager();
        $node = cask_node();
        $agent->answer('tools.homebrew-cask-candidate', ['version' => '3.003'])
            ->answer('tools.homebrew-cask-install', [])->answer('tools.homebrew-cask-update', [])->answer('tools.homebrew-cask-remove', []);
        expect($manager->candidateVersion($node, 'font-hack', ToolOperation::Install))->toBe('3.003');
        $manager->install($node, 'font-hack');
        $manager->update($node, 'font-hack');
        expect($manager->planRemoval($node, 'font-hack')->packages)->toBe(['font-hack']);
        $manager->remove($node, 'font-hack');
        expect($agent->commands())->toBe(['tools.homebrew-cask-candidate', 'tools.homebrew-cask-install', 'tools.homebrew-cask-update', 'tools.homebrew-cask-remove']);
        foreach ($agent->calls as $call) {
            expect($call['input'])->toBe(['package' => 'font-hack', 'architecture' => 'arm64']);
        }
    });

    it('keeps formula and cask command identities separate', function (): void {
        [$cask, $agent] = cask_manager();
        $formula = new HomebrewToolManager(new SemverVersionNormalizer, $agent);
        $agent->answer('tools.homebrew-cask-install', [])->answer('tools.homebrew-formula-install', []);
        $formula->install(cask_node(), 'docker');
        $cask->install(cask_node(), 'docker');
        expect($agent->commands())->toBe(['tools.homebrew-formula-install', 'tools.homebrew-cask-install']);
    });

    it('maps native cask refusals without raw manager output', function (string $code, string $step): void {
        [$manager, $agent] = cask_manager();
        $agent->refuse('tools.homebrew-cask-install', $code);
        expect(fn () => $manager->install(cask_node(), 'font-hack'))->toThrow(function (ToolManagerException $error) use ($step): void {
            expect($error->step)->toBe($step)->and($error->result)->toBeNull();
        });
    })->with([
        ['tools.homebrew_cask_source', 'cask-source'], ['tools.homebrew_cask_artifact', 'cask-artifact'],
        ['tools.homebrew_cask_authorization', 'cask-authorization'], ['tools.homebrew_cask_checksum', 'cask-checksum'],
        ['tools.homebrew_cask_version', 'cask-version'], ['tools.homebrew_output_invalid', 'install'],
        ['tools.homebrew_operation_failed', 'install'], ['tools.homebrew_scope_absent', 'manager-absent'],
        ['tools.homebrew_scope_conflict', 'manager-conflict'], ['tools.homebrew_scope_unreadable', 'manager-probe'],
    ]);

    it('reads the native manager version and preserves absence and ambiguous inventories', function (): void {
        [$manager, $agent] = cask_manager();
        $agent->answer('tools.homebrew-cask-candidate', ['version' => null]);
        expect($manager->managerVersion(cask_node()))->toBe('Homebrew 4.6.15')
            ->and($manager->candidateVersion(cask_node(), 'missing', ToolOperation::Install))->toBeNull();
        $agent->answer('tools.homebrew-cask-inventory', ['packages' => [
            ['name' => 'font-hack', 'versions' => ['3.003'], 'unverifiable' => false],
            ['name' => 'ambiguous', 'versions' => ['1.0', '2.0'], 'unverifiable' => false],
            ['name' => 'unreadable', 'versions' => [], 'unverifiable' => true],
        ]]);
        $inventory = $manager->installedInventory(cask_node());
        expect($inventory->versionFor('font-hack'))->toBe('3.003')->and($inventory->versionFor('missing'))->toBeNull();
        expect(fn () => $inventory->versionFor('ambiguous'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('unreadable'))->toThrow(ToolManagerException::class);
        $agent->refuse('tools.homebrew-cask-inventory', 'tools.homebrew_output_invalid');
        expect(fn () => $manager->installedVersion(cask_node(), 'font-hack'))->toThrow(ToolManagerException::class);
    });

    it('returns native adoption facts without creating Tool rows', function (?string $version, ?string $block): void {
        [$manager, $agent] = cask_manager();
        $agent->answer('tools.homebrew-cask-adoption', ['installed_version' => $version, 'block' => $block]);
        expect($manager->inspectForAdoption(cask_node(), 'font-hack'))->toEqual(new ToolAdoptionFact($version, $block));
        expect(Tool::query()->count())->toBe(0);
    })->with([['3.003', null], [null, null], ['3.003', 'authorization_required'], ['3.003', 'unsupported_source'], ['3.003', 'unsupported_artifact'], ['3.003', 'version_unreadable']]);

    it('rejects Linux, qualified names, unknown CPUs, and removal candidates before agent calls', function (): void {
        [$manager, $agent] = cask_manager();
        foreach ([fn () => $manager->install(cask_node('linux'), 'font-hack'), fn () => $manager->install(cask_node(), 'other/cask/font-hack'), fn () => $manager->remove(cask_node(architecture: 'i386'), 'font-hack'), fn () => $manager->candidateVersion(cask_node(), 'font-hack', ToolOperation::Remove)] as $call) {
            expect($call)->toThrow(ToolManagerException::class);
        }
        expect($agent->calls)->toBe([]);
    });

    it('retries a failed cask install through native results', function (): void {
        $node = cask_saved_node();
        [$manager, $agent] = cask_manager();
        $agent->refuse('tools.homebrew-cask-candidate', 'tools.homebrew_cask_checksum');
        $install = cask_install_action($manager);
        $failure = cask_operation_exception(fn () => $install->execute(new InstallToolData($node->id, 'brew-cask', 'font-hack', null)));
        expect($failure->errorCode)->toBe('tool.install_failed')->and(Tool::query()->sole()->status)->toBe(ToolStatus::Failed);
        $agent->answer('tools.homebrew-cask-candidate', ['version' => '3.003']);
        $agent->answer('tools.homebrew-cask-install', function () use ($agent): array {
            $agent->answer('tools.homebrew-cask-inventory', ['packages' => [['name' => 'font-hack', 'versions' => ['3.003'], 'unverifiable' => false]]]);

            return [];
        });
        $result = $install->execute(new InstallToolData($node->id, 'brew-cask', 'font-hack', null));
        expect($result->outcome)->toBe(ToolOutcome::Applied)->and($result->tool->status)->toBe(ToolStatus::Installed)->and($result->tool->installed_version)->toBe('3.003');
    });

    it('records failed updates and removals when the native cask check refuses authorization', function (string $operation): void {
        $node = cask_saved_node();
        $record = $node->toolManagers()->create(['name' => ToolManagerName::BrewCask, 'status' => LifecycleStatus::Active]);
        $tool = $node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'font-hack', 'status' => ToolStatus::Installed, 'installed_version' => '3.003']);
        [$manager, $agent] = cask_manager();
        $agent->answer('tools.homebrew-cask-inventory', ['packages' => [['name' => 'font-hack', 'versions' => ['3.003'], 'unverifiable' => false]]]);
        $agent->refuse($operation === 'update' ? 'tools.homebrew-cask-candidate' : 'tools.homebrew-cask-remove', 'tools.homebrew_cask_authorization');
        $action = $operation === 'update' ? cask_update_action($manager) : cask_remove_action($manager);
        $failure = cask_operation_exception(fn () => $action->execute($tool));
        expect($failure->errorCode)->toBe('tool.'.$operation.'_failed')->and($tool->refresh()->status)->toBe(ToolStatus::Failed);
    })->with(['update', 'remove']);

    it('deletes Tool intent only after the native removal passes', function (): void {
        $node = cask_saved_node();
        $record = $node->toolManagers()->create(['name' => ToolManagerName::BrewCask, 'status' => LifecycleStatus::Active]);
        $tool = $node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'font-hack', 'status' => ToolStatus::Installed, 'installed_version' => '3.003']);
        [$manager, $agent] = cask_manager();
        $agent->answer('tools.homebrew-cask-inventory', ['packages' => [['name' => 'font-hack', 'versions' => ['3.003'], 'unverifiable' => false]]]);
        $agent->answer('tools.homebrew-cask-remove', function () use ($agent): array {
            $agent->answer('tools.homebrew-cask-inventory', ['packages' => []]);

            return [];
        });
        $result = cask_remove_action($manager)->execute($tool);
        expect($result->outcome)->toBe(ToolOutcome::Applied)->and(Tool::query()->whereKey($tool->id)->exists())->toBeFalse();
    });
});

/** @return array{HomebrewCaskToolManager, FakeAgentCommandRunner} */
function cask_manager(): array
{
    $agent = new FakeAgentCommandRunner()->answer('tools.inspect-homebrew-scope', ['prefix' => '/opt/homebrew', 'home' => '/Users/mini'])
        ->answer('tools.homebrew-version', ['version' => 'Homebrew 4.6.15'])->answer('tools.homebrew-cask-inventory', ['packages' => []]);

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

function cask_install_action(HomebrewCaskToolManager $manager): InstallToolAction
{
    $registry = new ToolManagerRegistry([$manager]);

    return new InstallToolAction(
        managers: $registry,
        constraints: new VersionConstraint,
        lock: new ImmediateToolOperationLock,
        materializer: new NativeToolManagerMaterializer($registry, new NativeToolManagerScopeLock),
        eligibility: new ToolNodeEligibility,
    );
}

function cask_update_action(HomebrewCaskToolManager $manager): UpdateToolAction
{
    return new UpdateToolAction(
        managers: new ToolManagerRegistry([$manager]),
        constraints: new VersionConstraint,
        lock: new ImmediateToolOperationLock,
        eligibility: new ToolNodeEligibility,
    );
}

function cask_remove_action(HomebrewCaskToolManager $manager): RemoveToolAction
{
    return new RemoveToolAction(
        managers: new ToolManagerRegistry([$manager]),
        lock: new ImmediateToolOperationLock,
        eligibility: new ToolNodeEligibility,
    );
}

function cask_exception(callable $callback): ToolManagerException
{
    try {
        $callback();
    } catch (ToolManagerException $exception) {
        return $exception;
    }

    throw new RuntimeException('The Homebrew cask operation succeeded.');
}

function cask_operation_exception(callable $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        return $exception;
    }

    throw new RuntimeException('The Homebrew cask tool operation succeeded.');
}
