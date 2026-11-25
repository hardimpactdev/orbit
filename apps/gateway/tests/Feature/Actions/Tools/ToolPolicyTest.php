<?php

declare(strict_types=1);

use App\Actions\Tools\ManageToolPolicyAction;
use App\Actions\Tools\ScanToolInventoryAction;
use App\Actions\Tools\StartToolConvergeAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptSourceTrust;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolConvergeObservations;
use App\Domain\Tools\ToolIntentProjection;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TestOrbitHome;

uses(LazilyRefreshDatabase::class);

function policyNode(string $name, int $address = 10): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.'.$address, 'wireguard_ip' => '10.44.0.'.$address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'test-host-key',
    ]));
}

function policySignedRepository(string $suite = '1.10'): array
{
    return ['name' => 'proof', 'uris' => ['https://example.test/apt'], 'suites' => [$suite], 'components' => ['main'],
        'signing_key' => ['url' => 'https://example.test/key.asc', 'fingerprint' => str_repeat('A', 40)]];
}

function policySwamp(): void
{
    Queue::fake();
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    SwampNodeFixture::ensure();
}

function policyComplete(Operation $operation, string $status, array $result): void
{
    $operation->update(['state' => ['swamp_run_id' => 'test-run']]);
    app(ToolIntentProjection::class)->apply($operation, ['run_id' => 'test-run', 'status' => $status,
        'started_at' => now()->toAtomString(), 'finished_at' => now()->toAtomString(), 'result' => $result]);
}

function policyTool(Node $node, string $package, ?string $desired): Tool
{
    $manager = ToolManagerRecord::query()->firstOrCreate(['node_id' => $node->id, 'name' => 'apt'], ['status' => 'active']);

    return Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => $package,
        'desired_version' => $desired, 'installed_version' => '1.0-1', 'status' => 'installed', 'owned' => true]);
}

function policyFile(array $packages, ?string $path = null): string
{
    $path ??= TestOrbitHome::scratch('tools.yaml');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, json_encode(['schema' => 1, 'packages' => $packages], JSON_THROW_ON_ERROR));

    return $path;
}

it('exports divergent per-Node pins without collapsing them into an unrepresentable catalog', function (): void {
    $first = policyTool(policyNode('dev'), 'tree', '1.10');
    $second = policyTool(policyNode('prod', 11), 'tree', '1.1');
    $policy = app(ManageToolPolicyAction::class);

    $rows = $policy->show('snapshot')['document']['memberships'];
    expect(array_column($rows, 'id'))->toBe([$first->id, $second->id])
        ->and(array_column(array_column($rows, 'desired'), 'version'))->toBe(['1.10', '1.1'])
        ->and(array_column($rows, 'installed_version'))->toBe(['1.0-1', '1.0-1']);
    expect(fn () => $policy->show('catalog'))->toThrow(fn (ResourceOperationException $error) => expect($error->details['reason'])->toBe('per_node_divergence'));
    $path = policyFile([['manager' => 'apt', 'package' => 'tree', 'version' => '1.1']]);
    expect(fn () => $policy->change('file', $path))->toThrow(fn (ResourceOperationException $error) => expect($error->details['reason'])->toBe('different_coordinate'));
    expect($policy->show()['mode'])->toBe('database')
        ->and(DB::table('tool_policy_snapshots')->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0);
});

it('compares nested signed repository values exactly for export and activation', function (): void {
    $first = policyTool(policyNode('dev'), 'nano', '1.10');
    $second = policyTool(policyNode('prod', 11), 'nano', '1.10');
    $first->update(['desired_repository' => policySignedRepository()]);
    $second->update(['desired_repository' => policySignedRepository('1.1')]);
    $policy = app(ManageToolPolicyAction::class);

    expect(fn () => $policy->show('catalog'))->toThrow(fn (ResourceOperationException $error) => expect($error->details['reason'])->toBe('per_node_divergence'));
    $path = policyFile([['manager' => 'apt', 'package' => 'nano', 'version' => '1.10', 'repository' => policySignedRepository()]]);
    expect(fn () => $policy->change('file', $path))->toThrow(fn (ResourceOperationException $error) => expect($error->details['reason'])->toBe('different_coordinate'));
    expect($policy->show()['mode'])->toBe('database')->and(DB::table('tool_policy_snapshots')->count())->toBe(0);
});

it('applies file edits to admitted membership only and snapshots the effective and observed values on return', function (): void {
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0-1');
    $entry = ['manager' => 'apt', 'package' => 'tree', 'version' => '1.0-1'];
    $path = policyFile([$entry]);
    $policy = app(ManageToolPolicyAction::class);
    expect($policy->change('file', $path))->toMatchArray(['mode' => 'file', 'snapshot_id' => 1]);

    policyFile([array_replace($entry, ['version' => '2.0-1']), ['manager' => 'apt', 'package' => 'nano', 'version' => '1.2-1']], $path);

    $intent = app(ToolConvergeIntent::class)->capture($node);
    expect($intent)->toHaveCount(1)->and($intent[0])->toMatchArray(['id' => $tool->id, 'desired_version' => '2.0-1', 'installed_version' => '1.0-1']);
    $operations = app(StartToolOperationAction::class);
    expect(fn () => $operations->execute('update', $node->id, 'apt', 'tree', null, $tool, version: '2.0-1'))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.file_override'));
    expect(fn () => $operations->adopt($node->id, 'apt', 'ed', null, null, null))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.not_in_catalog'));
    expect($policy->change('database', null))->toMatchArray(['mode' => 'database', 'snapshot_id' => 2])
        ->and($tool->refresh()->desired_version)->toBe('2.0-1')
        ->and($tool->installed_version)->toBe('1.0-1')
        ->and(Operation::query()->count())->toBe(0);
});

it('keeps legacy observed versions floating and refuses a catalog export without an exact desired coordinate', function (): void {
    $tool = policyTool(policyNode('legacy'), 'tree', null);
    $policy = app(ManageToolPolicyAction::class);

    expect($policy->show('snapshot')['document']['memberships'][0])->toMatchArray([
        'id' => $tool->id, 'desired' => ['version' => null, 'constraint' => null, 'repository' => null], 'installed_version' => '1.0-1',
    ]);
    expect(fn () => $policy->show('catalog'))->toThrow(fn (ResourceOperationException $error) => expect($error->details['reason'])->toBe('floating_coordinate'));
});

it('never admits existing Conn Server or role packages to generic convergence', function (): void {
    policySwamp();
    $node = policyNode('host');
    policyTool($node, 'conn-server', '1.0-1');
    policyTool($node, 'php8.5-cli', '1.0-1');
    foreach (['containerd', 'apparmor', 'libnss3', 'dpkg-repack', 'sqlite3', 'postgresql-client', 'rsync', 'caddy', 'jq', 'python3-venv', 'valkey-server', 'docker-ce'] as $package) {
        $tool = policyTool($node, $package, null);
        $operations = app(StartToolOperationAction::class);
        expect(fn () => $operations->execute('update', $node->id, 'apt', $package, null, $tool, version: '1.0-1'))
            ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.package_protected'));
        expect(app(ToolConvergeIntent::class)->capture($node))->toBe([]);
        $removal = $operations->execute('remove', $node->id, 'apt', $package, null, $tool);
        expect($removal->status->value)->toBe('succeeded')->and($removal->result['execution'])->toBe('membership_only')
            ->and($removal->input['tool_id'])->toBe($tool->id)->and($tool->fresh())->toBeNull();
    }

    expect(app(ToolConvergeIntent::class)->capture($node))->toBe([]);
});

it('blocks discovered host recipe apt packages but not names used only in a container folder', function (): void {
    $node = policyNode('host');
    $owned = ['caddy', 'containerd', 'docker-ce', 'jq', 'python3-venv', 'valkey-server'];
    NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'packages', 'subject' => 'apt', 'digest' => str_repeat('a', 64),
        'observed_at' => now(), 'received_at' => now(), 'data' => ['scope' => '/var/lib/dpkg/status',
            'packages' => array_map(static fn (string $name): array => ['name' => $name, 'version' => '2.0'], [...$owned, 't3'])]]);
    $packages = app(ScanToolInventoryAction::class)->execute($node->id)->managers[3]->packages;
    $discovered = array_column($packages, 'adoptionBlock', 'package');
    expect($discovered['t3'])->toBeNull();
    foreach ($owned as $package) {
        expect($discovered[$package])->toBe('protected');
        expect(fn () => app(StartToolOperationAction::class)->adopt($node->id, 'apt', $package, null, null, null))
            ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.adoption_unsupported'));
    }
});

it('can relinquish an obsolete floating protected row and then activate file mode', function (): void {
    policySwamp();
    $node = policyNode('host');
    $tool = policyTool($node, 'dpkg-repack', null);
    $policy = app(ManageToolPolicyAction::class);
    expect(fn () => $policy->show('catalog'))->toThrow(ResourceOperationException::class);
    $removed = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', $tool->package, null, $tool);
    expect($removed->result['execution'])->toBe('membership_only')
        ->and($policy->change('file', policyFile([]))['mode'])->toBe('file');
});

it('keeps the signed source requested by a failed install and retries at the same coordinate', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10');
    policyComplete($install, 'failed', ['steps' => ['Apply the package' => ['status' => 'failed']]]);

    $membership = app(ManageToolPolicyAction::class)->show('snapshot')['document']['memberships'][0];
    expect($membership)->toMatchArray(['recorded_repository' => null, 'desired' => ['version' => '1.10', 'constraint' => null, 'repository' => $source]])
        ->and(app(ToolConvergeIntent::class)->capture($node)[0]['repository'])->toBe($source);
    $retry = $operations->execute('install', $node->id, 'apt', 'tree', null);
    expect($retry->input)->toMatchArray(['repository' => $source, 'desired_version' => '1.10', 'repository_given' => true]);
});

it('keeps a failed signed-source update separate from the last verified source', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.10');
    $original = policySignedRepository('1.10');
    $desired = policySignedRepository('1.1');
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $original]);
    $tool->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
    $operations = app(StartToolOperationAction::class);
    $operation = $operations->execute('update', $node->id, 'apt', 'tree', null, $tool, $desired, '2.0');
    policyComplete($operation, 'failed', ['steps' => ['Apply the package' => ['status' => 'failed']]]);
    expect($tool->refresh()->desired_repository)->toBe($desired)->and($verified->refresh()->definition)->toBe($original);
    expect(app(ToolConvergeIntent::class)->capture($node)[0])->toMatchArray(['repository' => $desired, 'desired_version' => '2.0']);
    expect($operations->execute('update', $node->id, 'apt', 'tree', null, $tool)->input)->toMatchArray(['repository' => $desired, 'desired_version' => '2.0']);
});

it('refuses to switch away from a catalog that clears or renames verified trust', function (bool $rename): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.10');
    $source = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $tool->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    $entry = ['manager' => 'apt', 'package' => 'tree', 'version' => '1.10'];
    $path = policyFile([[...$entry, 'repository' => $source]]);
    $policy = app(ManageToolPolicyAction::class);
    $policy->change('file', $path);
    $snapshots = DB::table('tool_policy_snapshots')->count();
    policyFile([$rename ? [...$entry, 'repository' => [...$source, 'name' => 'renamed']] : $entry], $path);
    expect(fn () => $policy->change('database', null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    expect($policy->show('snapshot')['mode'])->toBe('file')->and($tool->fresh()->desired_repository)->toBe($source)
        ->and(DB::table('tool_policy_snapshots')->count())->toBe($snapshots);
})->with([false, true]);

it('can cancel or repair a pinned first install after manager setup failed', function (bool $repair): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'composer', 'laravel/installer', null, version: '5.18.0');
    policyComplete($install, 'failed', ['steps' => ['Set up the Tool Manager' => ['status' => 'failed']]]);
    $tool = Tool::query()->findOrFail($install->input['tool_id']);
    expect($tool->installed_version)->toBeNull()->and($tool->desired_version)->toBe('5.18.0')
        ->and($tool->manager->status->value)->toBe('failed');
    if ($repair) {
        $plans = array_column(app(ToolConvergeIntent::class)->capture($node), null, 'id');
        $observations = app(ToolConvergeObservations::class);
        $observations->record($node->id, $plans, $observations->validate([
            ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '5.18.0'],
        ], $plans, true));
        $tool->refresh();
        expect($tool->manager->status->value)->toBe('active');
    }
    $removal = $operations->execute('remove', $node->id, 'composer', $tool->package, null, $tool);
    if ($repair) {
        expect($removal->status->terminal())->toBeFalse();
    } else {
        expect($removal->result['execution'])->toBe('membership_only')->and($tool->fresh())->toBeNull();
        expect(app(ToolConvergeIntent::class)->capture($node))->toBe([]);
    }
})->with([false, true]);

it('budgets all admitted Tools without capping membership and keeps shared repository preferences', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $tools = [];
    foreach (['tree', 'nano', 'ed', 'ripgrep'] as $package) {
        $tool = policyTool($node, $package, '1.10');
        $tool->update(['desired_repository' => $source]);
        $tools[$package] = $tool->id;
    }
    $converge = app(StartToolConvergeAction::class);
    $whole = $converge->execute($node, 'test');
    expect(array_column($whole->input['intent']['tools'], 'id'))->toBe(array_values($tools))
        ->and($whole->input['intent']['tools_seconds'])->toBe(8900)->and($whole->input['run_budget_seconds'])->toBe(9100);
    $whole->update(['status' => 'succeeded', 'lock_key' => null]);
    $foreign = policyTool(policyNode('other', 11), 'tree', '1.10');
    expect(fn () => $converge->execute($node, 'test', [$foreign->id]))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.scope_invalid'));
    $operation = $converge->execute($node, 'test', [$tools['nano'], $tools['ed']]);
    expect(array_column($operation->input['intent']['tools'], 'id'))->toBe([$tools['nano'], $tools['ed']])
        ->and($operation->input['intent']['tools'][0]['repository_packages'])->toBe(['ed', 'nano', 'ripgrep', 'tree']);
    $operation->update(['status' => 'succeeded', 'lock_key' => null]);
    $control = SwampNodeFixture::ensure();
    $control->accessibleNodes()->attach($node);
    $accepted = $this->withServerVariables(['REMOTE_ADDR' => $control->wireguard_ip])->postJson("/api/v1/nodes/{$node->id}/tools/converge", ['tool_ids' => array_values($tools)])->assertAccepted();
    expect(array_column(Operation::query()->findOrFail($accepted->json('data.id'))->input['intent']['tools'], 'id'))->toBe(array_values($tools));
});

it('resolves only selected packages and their shared signed apt sources', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $nano = policyTool($node, 'nano', '1.10');
    $ed = policyTool($node, 'ed', '1.10');
    $plain = static fn (Tool $tool): array => ['manager' => 'apt', 'package' => $tool->package, 'version' => '1.10'];
    $source = policySignedRepository();
    $path = policyFile([$plain($nano), $plain($ed)]);
    app(ManageToolPolicyAction::class)->change('file', $path);
    policyFile([$plain($nano)], $path);
    $converge = app(StartToolConvergeAction::class);
    $run = $converge->execute($node, 'test', [$nano->id]);
    expect(array_column($run->input['intent']['tools'], 'id'))->toBe([$nano->id]);
    $run->update(['status' => 'succeeded', 'lock_key' => null]);
    expect(fn () => $converge->execute($node, 'test'))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.not_in_catalog'));
    policyFile([[...$plain($nano), 'repository' => $source], [...$plain($ed), 'repository' => policySignedRepository('other')]], $path);
    expect(fn () => $converge->execute($node, 'test', [$nano->id]))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
});

it('allows disjoint Tool writers but preserves the overlapping and global policy fences', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $other = policyNode('other', 11);
    $tool = policyTool($node, 'tree', '1.10');
    $operations = app(StartToolOperationAction::class);
    $rollout = Operation::query()->create(['kind' => ConnServerRollout::Kind, 'node_id' => $other->id, 'target_id' => $other->id,
        'target_type' => 'fleet', 'triggered_by' => 'operator', 'status' => 'running', 'sequence' => 0, 'input' => ['intent' => ['nodes' => [['node_id' => $other->id]]]]]);
    $converge = app(StartToolConvergeAction::class)->execute($node, 'test', [$tool->id]);
    expect($converge->status->terminal())->toBeFalse();
    expect($operations->execute('install', $node->id, 'composer', 'laravel/installer', null, version: '5.18.0')->status->terminal())->toBeFalse();
    expect($operations->execute('install', $other->id, 'apt', 'nano', null)->status->terminal())->toBeFalse();
    expect(fn () => $operations->execute('install', $node->id, 'apt', 'nano', null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(fn () => app(ToolConvergeIntent::class)->assertIdle($other->id, ['conn-server']))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    app(ToolConvergeIntent::class)->assertIdle($node->id, ['conn-server']);
    expect(fn () => app(ManageToolPolicyAction::class)->change('file', null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.mode_busy'));
    expect($rollout->fresh()->status->value)->toBe('running');
});

it('admits the remaining preference packages for both membership changes and refuses unilateral trust changes', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $reference = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $reference->id, 'desired_repository' => $source]);
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'nano', null, repository: $source, version: '1.10');
    expect($install->input)->toMatchArray(['repository_packages' => ['nano', 'tree'], 'repository_given' => false]);
    policyComplete($install, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    $update = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree);
    expect($update->input)->toMatchArray(['repository_packages' => ['nano', 'tree'], 'repository_given' => false]);
    policyComplete($update, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);

    $entry = static fn (string $package, array $repository): array => ['manager' => 'apt', 'package' => $package, 'version' => '1.10', 'repository' => $repository];
    $path = policyFile([$entry('tree', $source), $entry('nano', $source)]);
    app(ManageToolPolicyAction::class)->change('file', $path);
    policyFile([$entry('tree', policySignedRepository('1.1')), $entry('nano', $source)], $path);
    expect(fn () => $operations->execute('update', $node->id, 'apt', 'tree', null, $tree))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    expect(fn () => app(ToolConvergeIntent::class)->capture($node))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    $remove = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree);
    expect($remove->input)->toMatchArray(['repository_packages' => ['nano'], 'remove_repository' => false]);
});

it('holds admissions for retained timeout locks and old admitted Tools without holding role-only work', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.10');
    $path = policyFile([['manager' => 'apt', 'package' => 'tree', 'version' => '1.10']]);
    $fixture = ['node_id' => $node->id, 'target_id' => $node->id, 'target_type' => 'node_tools', 'triggered_by' => 'operator', 'sequence' => 0];
    $timeout = Operation::query()->create([...$fixture, 'kind' => 'tool.update', 'status' => 'failed', 'error_code' => 'operation.timed_out',
        'lock_key' => OperationStore::lockKey('node_tools', $node->id), 'input' => []]);
    $policy = app(ManageToolPolicyAction::class);
    $converge = app(StartToolConvergeAction::class);
    expect(fn () => $converge->execute($node, 'test'))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(fn () => $policy->change('file', $path))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.mode_busy'));
    $timeout->update(['lock_key' => null]);

    $roleRun = Operation::query()->create([...$fixture, 'kind' => 'node.converge', 'status' => 'running',
        'input' => ['_converge' => ['intent' => ['tools' => [['id' => $tool->id]]]]]]);
    expect(fn () => app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'tree', null, $tool))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(fn () => $policy->change('file', $path))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.mode_busy'));
    $roleRun->update(['input' => ['_converge' => ['intent' => ['tools' => []]]]]);
    expect($policy->change('file', $path)['mode'])->toBe('file');
});

it('keeps an unavailable cask local and a failed existing Tool repair eligible for direct correction', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tree = policyTool($node, 'tree', '2.0');
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'brew-cask', 'status' => 'active']);
    $cask = Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'sample',
        'desired_version' => '1.5.13,601', 'installed_version' => '1.5.13,600', 'status' => 'installed', 'owned' => true]);
    $plans = array_column(app(ToolConvergeIntent::class)->capture($node), null, 'id');
    expect($plans[$cask->id]['manager_unavailable'])->toBeTrue()->and($plans[$tree->id]['manager'])->toBe('apt');
    $results = app(ToolConvergeObservations::class);
    $failed = $results->validate([
        ['id' => $tree->id, 'status' => 'failed', 'installed_version' => '1.0-1'],
        ['id' => $cask->id, 'status' => 'failed', 'installed_version' => null],
    ], $plans, true);
    $results->record($node->id, $plans, $failed);
    expect($cask->refresh()->installed_version)->toBe('1.5.13,600')->and($cask->error_code)->toBe('tool.manager_unsupported')
        ->and($tree->refresh()->failed_operation)->toBe(ToolOperation::Update);
    $correct = app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'tree', null, $tree, version: '1.0-1');
    expect($correct->input['desired_version'])->toBe('1.0-1');
});

it('records a successful cask build change as a change in both executors', function (): void {
    policySwamp();
    $node = policyNode('mac');
    $node->update(['platform' => 'macos', 'architecture' => 'arm64']);
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'brew-cask', 'status' => 'active']);
    $tool = Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'sample',
        'desired_version' => '1.5.13,600', 'installed_version' => '1.5.13,600', 'status' => 'installed', 'owned' => true]);
    $run = app(StartToolOperationAction::class)->execute('update', $node->id, 'brew-cask', 'sample', null, $tool, version: '1.5.13,601');
    policyComplete($run, 'succeeded', ['decision' => ['ready' => true, 'installed_version' => '1.5.13', 'installed_coordinate' => '1.5.13,600'],
        'observation' => ['installed_version' => '1.5.13', 'installed_coordinate' => '1.5.13,601'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    expect($run->refresh()->result['outcome'])->toBe('applied')->and($tool->refresh()->installed_version)->toBe('1.5.13,601');
    $tool->update(['desired_version' => '1.5.13,602']);
    $plans = array_column(app(ToolConvergeIntent::class)->capture($node), null, 'id');
    $results = app(ToolConvergeObservations::class);
    $observed = $results->validate([['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.5.13,602']], $plans, true);
    $results->record($node->id, $plans, $observed);
    expect($tool->refresh()->installed_version)->toBe('1.5.13,602');
});

it('refuses to leave file mode while two Tools on one Node define a shared apt source differently', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    foreach (['nano', 'tree'] as $package) {
        policyTool($node, $package, '1.10')->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    }
    $entry = static fn (string $package, array $repository): array => ['manager' => 'apt', 'package' => $package, 'version' => '1.10', 'repository' => $repository];
    $path = policyFile([$entry('nano', $source), $entry('tree', $source)]);
    $policy = app(ManageToolPolicyAction::class);
    $policy->change('file', $path);
    $snapshots = DB::table('tool_policy_snapshots')->count();
    policyFile([$entry('nano', $source), $entry('tree', policySignedRepository('1.1'))], $path);

    expect(fn () => $policy->change('database', null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    expect($policy->show()['mode'])->toBe('file')->and(DB::table('tool_policy_snapshots')->count())->toBe($snapshots)
        ->and(Tool::query()->pluck('desired_repository')->all())->toBe([$source, $source]);
});

it('removes a first install that failed at manager setup as membership only after a sibling repaired the manager', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    $failed = $operations->execute('install', $node->id, 'composer', 'laravel/installer', null, version: '5.18.0');
    policyComplete($failed, 'failed', ['steps' => ['Set up the Tool Manager' => ['status' => 'failed']]]);
    $tool = Tool::query()->findOrFail($failed->input['tool_id']);
    $sibling = $operations->execute('install', $node->id, 'composer', 'phpunit/phpunit', null, version: '12.0.0');
    policyComplete($sibling, 'succeeded', ['decision' => ['ready' => true, 'manager_version' => '2.8.0'], 'observation' => ['installed_version' => '12.0.0'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    expect($tool->refresh()->manager->status->value)->toBe('active');

    $removal = $operations->execute('remove', $node->id, 'composer', $tool->package, null, $tool);
    expect($removal->status->value)->toBe('succeeded')->and($removal->result['execution'])->toBe('membership_only')
        ->and($tool->fresh())->toBeNull()->and(Tool::query()->where('package', 'phpunit/phpunit')->exists())->toBeTrue();
});

it('relinquishes record-only memberships without a native SSH host key but still requires one for physical work', function (): void {
    policySwamp();
    $node = policyNode('host');
    $node->forceFill(['ssh_host_key' => null, 'ssh_host_key_type' => null])->save();
    $operations = app(StartToolOperationAction::class);
    $legacy = policyTool($node, 'dpkg-repack', null);
    expect($operations->execute('remove', $node->id, 'apt', 'dpkg-repack', null, $legacy)->result['execution'])->toBe('membership_only')
        ->and(app(ManageToolPolicyAction::class)->show('catalog')['document']['packages'])->toBe([]);

    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'composer', 'status' => 'failed']);
    $failed = Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'laravel/installer', 'desired_version' => '5.18.0',
        'status' => 'failed', 'failed_operation' => 'install', 'error_code' => 'tool.manager_provision_failed']);
    $cancel = $operations->execute('remove', $node->id, 'composer', 'laravel/installer', null, $failed);
    expect($cancel->result['execution'])->toBe('membership_only')->and($cancel->input)->not->toHaveKey('ssh')->and($failed->fresh())->toBeNull();

    $tree = policyTool($node, 'tree', '1.10');
    expect(fn () => $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('swamp.ssh_target_unavailable'));
    expect($tree->fresh())->not->toBeNull();
});

it('rewrites a recorded apt source after a failed same-name change may have replaced it on disk', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.10');
    $original = policySignedRepository('1.10');
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $original]);
    $tool->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
    $operations = app(StartToolOperationAction::class);
    $change = $operations->execute('update', $node->id, 'apt', 'tree', null, $tool, policySignedRepository('1.1'), '2.0');
    expect($change->input['repository_given'])->toBeTrue();
    policyComplete($change, 'failed', ['steps' => ['Publish the signing key' => ['status' => 'succeeded'], 'Write the apt source' => ['status' => 'succeeded'],
        'Refresh apt source' => ['status' => 'succeeded'], 'Apply the package' => ['status' => 'failed']]]);

    $revert = $operations->execute('update', $node->id, 'apt', 'tree', null, $tool->refresh(), $original, '1.10');
    expect($revert->input)->toMatchArray(['repository' => $original, 'repository_given' => true]);
    policyComplete($revert, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    $revert->update(['lock_key' => null]);
    expect($operations->execute('update', $node->id, 'apt', 'tree', null, $tool->refresh())->input['repository_given'])->toBeFalse();
});

it('never grants uninstall authority to a first install whose retry Orbit refused or failed', function (string $route): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    $failed = $operations->execute('install', $node->id, 'composer', 'laravel/installer', null, version: '5.18.0');
    policyComplete($failed, 'failed', ['steps' => ['Set up the Tool Manager' => ['status' => 'failed']]]);
    $tool = Tool::query()->findOrFail($failed->input['tool_id']);
    $sibling = $operations->execute('install', $node->id, 'composer', 'phpunit/phpunit', null, version: '12.0.0');
    policyComplete($sibling, 'succeeded', ['decision' => ['ready' => true, 'manager_version' => '2.8.0'], 'observation' => ['installed_version' => '12.0.0'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    if ($route === 'direct retry') {
        $retry = $operations->execute('install', $node->id, 'composer', 'laravel/installer', null, $tool->refresh());
        policyComplete($retry, 'failed', ['decision' => ['error_code' => 'tool.package_already_installed', 'installed_version' => '5.18.0']]);
        expect($tool->refresh()->error_code)->toBe('tool.package_already_installed');
    } else {
        $plans = array_column(app(ToolConvergeIntent::class)->capture($node), null, 'id');
        $observations = app(ToolConvergeObservations::class);
        $observations->record($node->id, $plans, $observations->validate([['id' => $tool->id, 'status' => 'failed', 'installed_version' => null]], $plans, false));
        expect($tool->refresh()->error_code)->toBe('tool.install_failed');
    }
    expect($tool->installed_version)->toBeNull();

    $removal = $operations->execute('remove', $node->id, 'composer', 'laravel/installer', null, $tool);
    expect($removal->status->value)->toBe('succeeded')->and($removal->result['execution'])->toBe('membership_only')
        ->and($removal->input)->not->toHaveKey('ssh')->and($tool->fresh())->toBeNull();
})->with(['direct retry', 'convergence']);

it('cleans the apt source of a refused first install without removing the package it never owned', function (bool $shared): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    if ($shared) {
        $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source, 'verified' => false]);
        policyTool($node, 'nano', '1.10')->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    }
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10');
    expect($install->input['repository_given'])->toBeTrue();
    policyComplete($install, 'failed', ['decision' => ['error_code' => 'tool.package_already_installed', 'installed_version' => '1.10'],
        'steps' => ['Write the apt source' => ['status' => 'succeeded']]]);
    $install->update(['lock_key' => null]);
    $tool = Tool::query()->findOrFail($install->input['tool_id']);
    expect($tool->only(['status', 'installed_version', 'error_code']))->toMatchArray(['installed_version' => null, 'error_code' => 'tool.package_already_installed'])
        ->and($tool->status->value)->toBe('failed');

    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    expect($removal->status->terminal())->toBeFalse()->and($removal->input)->toHaveKey('ssh')
        ->and($removal->input)->toMatchArray(['package_owned' => false, 'repository' => $source, 'remove_repository' => ! $shared,
            'repository_packages' => $shared ? ['nano'] : []])
        ->and(app(ToolIntentProjection::class)->input($removal)['intent']['package_owned'])->toBeFalse();
    $removal->update(['status' => 'cancelled', 'lock_key' => null]);
    $plain = $operations->execute('install', $node->id, 'apt', 'jq-like', null, version: '1.0');
    policyComplete($plain, 'failed', ['decision' => ['error_code' => 'tool.package_already_installed', 'installed_version' => '1.0']]);
    $plain->update(['lock_key' => null]);
    expect(Tool::query()->whereKey($plain->input['tool_id'])->exists())->toBeFalse();
    $installed = policyTool($node, 'ed', '1.0-1');
    expect($operations->execute('update', $node->id, 'apt', 'ed', null, $installed, version: '1.1-1')->input['package_owned'])->toBeTrue();
})->with(['last member' => [false], 'shared source' => [true]]);

it('keeps an apt source unverified after any write that may have changed it until a later write succeeds', function (string $ending): void {
    policySwamp();
    $node = policyNode('dev');
    $original = policySignedRepository('1.10');
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $original]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
    $member = $tree;
    $operations = app(StartToolOperationAction::class);
    if ($ending === 'cancelled convergence') {
        $write = app(StartToolConvergeAction::class)->execute($node, 'test', [$tree->id]);
        $write->update(['status' => 'cancelled', 'lock_key' => null]);
    } elseif ($ending === 'failed member removed') {
        $member = policyTool($node, 'nano', '1.10');
        $member->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
        $verified->update(['verified' => false]);
        $write = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, version: '2.0');
        expect($write->input['repository_given'])->toBeTrue();
        policyComplete($write, 'failed', ['steps' => ['Write the apt source' => ['status' => 'succeeded'], 'Apply the package' => ['status' => 'failed']]]);
        $write->update(['lock_key' => null]);
        $remove = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree->refresh());
        expect($remove->input)->toMatchArray(['remove_repository' => false, 'repository_packages' => ['nano']]);
        policyComplete($remove, 'succeeded', ['decision' => ['ready' => true], 'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
        $remove->update(['lock_key' => null]);
    } else {
        $write = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, policySignedRepository('1.1'), '2.0');
        expect($write->input['repository_given'])->toBeTrue();
        if ($ending === 'cancelled') {
            policyComplete($write, 'cancelled', []);
        } elseif ($ending === 'unreadable result') {
            expect(fn () => policyComplete($write, 'succeeded', ['decision' => ['ready' => true]]))->toThrow(ResourceOperationException::class);
        }
        $write->update(['status' => $ending === 'cancelled' ? 'cancelled' : 'failed', 'error_code' => match ($ending) {
            'cancelled' => null, 'timed out after the lock release' => 'operation.timed_out', default => 'operation.result_invalid',
        }, 'lock_key' => null]);
        expect($tree->refresh()->status->value)->toBe('installed');
    }

    $revert = $operations->execute('update', $node->id, 'apt', $member->package, null, $member->refresh(), $original, '1.10');
    expect($revert->input)->toMatchArray(['repository' => $original, 'repository_given' => true]);
    policyComplete($revert, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    $revert->update(['lock_key' => null]);
    expect($verified->refresh()->verified)->toBeTrue()
        ->and($operations->execute('update', $node->id, 'apt', $member->package, null, $member->refresh())->input['repository_given'])->toBeFalse();
})->with(['cancelled', 'timed out after the lock release', 'unreadable result', 'cancelled convergence', 'failed member removed']);

it('trusts a verified source again after a later successful write despite an older sibling failure', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $tree = policyTool($node, 'tree', '1.10');
    $nano = policyTool($node, 'nano', '1.10');
    foreach ([$tree, $nano] as $tool) {
        $tool->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    }
    $operations = app(StartToolOperationAction::class);
    $verified->update(['verified' => false]);
    $failed = $operations->execute('update', $node->id, 'apt', 'nano', null, $nano, version: '2.0');
    expect($failed->input['repository_given'])->toBeTrue();
    policyComplete($failed, 'failed', ['steps' => ['Write the apt source' => ['status' => 'failed']]]);
    $failed->update(['lock_key' => null]);
    $given = [];
    foreach (['1.11', '1.12', '1.13'] as $version) {
        $update = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree->refresh(), $source, $version);
        $given[] = $update->input['repository_given'];
        policyComplete($update, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => $version],
            'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
        $update->update(['lock_key' => null]);
    }
    expect($given)->toBe([true, false, false])->and($nano->refresh()->status->value)->toBe('failed');
});

it('backfills ownership, source trust and cleanup handles from what the previous release stored', function (): void {
    $node = policyNode('dev');
    $definition = static fn (string $name): array => [...policySignedRepository(), 'name' => $name];
    $sources = [];
    foreach (['clean', 'failed', 'cancelled', 'unconfirmed'] as $name) {
        $sources[$name] = AptRepository::query()->create(['node_id' => $node->id, 'name' => $name, 'definition' => $definition($name)]);
    }
    $installed = policyTool($node, 'ed', '1.0');
    $failedUpdate = policyTool($node, 'tree', '1.10');
    $failedUpdate->update(['status' => 'failed', 'failed_operation' => 'update', 'desired_repository' => $definition('failed')]);
    $firstInstall = policyTool($node, 'jq', null);
    $firstInstall->update(['status' => 'failed', 'failed_operation' => 'install', 'installed_version' => '1.6']);
    $run = static fn (string $kind, string $status, array $input, array $more = []): Operation => Operation::query()->create([
        'kind' => $kind, 'node_id' => $node->id, 'target_id' => $node->id, 'target_type' => 'node_tools', 'triggered_by' => 'operator',
        'status' => $status, 'sequence' => 0, 'input' => $input, ...$more]);
    $run('tool.update', 'cancelled', ['repository' => $definition('cancelled'), 'repository_given' => true]);
    $run('tool.remove', 'succeeded', ['repository' => $definition('unconfirmed'), 'remove_repository' => true], ['result' => ['repository_removed' => false]]);
    $run('tool.install', 'failed', ['manager' => 'apt', 'package' => 'orphan', 'tool_id' => 999, 'desired_version' => '2.0', 'version_constraint' => null,
        'repository' => $definition('orphaned'), 'repository_given' => true], ['error_code' => 'tool.package_already_installed']);
    $migration = require database_path('migrations/2027_03_28_000001_record_tool_ownership_and_source_trust.php');
    $migration->down();
    $migration->up();

    expect(DB::table('apt_repositories')->orderBy('id')->pluck('verified', 'name')->map(static fn (mixed $value): bool => (bool) $value)->all())
        ->toBe(['clean' => true, 'failed' => false, 'cancelled' => false, 'unconfirmed' => false])
        ->and(Tool::query()->whereIn('id', [$installed->id, $failedUpdate->id, $firstInstall->id])->orderBy('id')->pluck('owned')->all())->toBe([true, true, false]);
    $handle = Tool::query()->where('package', 'orphan')->sole();
    expect($handle->only(['owned', 'installed_version', 'desired_version', 'error_code']))->toBe(['owned' => false, 'installed_version' => null,
        'desired_version' => '2.0', 'error_code' => 'tool.package_already_installed'])
        ->and($handle->status->value)->toBe('failed')->and($handle->desired_repository)->toBe($definition('orphaned'));
});

/** A complete source-only removal: the package step is skipped and the source and its pins are removed. */
function policyCleaned(Operation $operation, bool $source = true, bool $pins = true): void
{
    policyComplete($operation, 'succeeded', ['decision' => ['ready' => true], 'steps' => ['Assert package state' => ['status' => 'succeeded'],
        'Remove the apt source' => ['status' => $source ? 'succeeded' : 'failed'], 'Remove repository pins' => ['status' => $pins ? 'succeeded' : 'failed']]]);
    $operation->update(['lock_key' => null]);
}

/** A refused first apt install of `tree` from `$source`; its run may already have written the source or pins. */
function policyRefusedInstall(Node $node, array $source): Tool
{
    $install = app(StartToolOperationAction::class)->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10');
    policyComplete($install, 'failed', ['decision' => ['error_code' => 'tool.package_already_installed', 'installed_version' => '1.10']]);
    $install->update(['lock_key' => null]);

    return Tool::query()->findOrFail($install->input['tool_id']);
}

it('keeps a refused install unowned through a failed cleanup and its retry', function (): void {
    policySwamp();
    $node = policyNode('dev');
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $tool = policyRefusedInstall($node, policySignedRepository());
    $operations = app(StartToolOperationAction::class);
    $cleanup = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    expect($cleanup->input['package_owned'])->toBeFalse();
    policyComplete($cleanup, 'failed', ['decision' => ['ready' => false, 'error_code' => 'tool.version_probe_failed'], 'steps' => ['Read manager version' => ['status' => 'failed']]]);
    $cleanup->update(['lock_key' => null]);
    expect($tool->refresh()->failed_operation)->toBe(ToolOperation::Remove)->and($tool->owned)->toBeFalse();

    $retry = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    expect($retry->input)->toMatchArray(['package_owned' => false, 'installed_version' => null, 'remove_repository' => true]);
});

it('refuses ownership to an interrupted first install on every retry and cleans it as a record', function (string $ending): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'composer', 'laravel/installer', null, version: '5.18.0');
    $install->update(match ($ending) {
        'queued cancellation' => ['status' => 'cancelled', 'lock_key' => null],
        'unreadable result' => ['status' => 'failed', 'error_code' => 'operation.result_invalid', 'lock_key' => null, 'state' => ['swamp_run_id' => 'run']],
        'timed out after the lock release' => ['status' => 'failed', 'error_code' => 'operation.timed_out', 'lock_key' => null, 'state' => ['swamp_run_id' => 'run']],
    });
    $tool = Tool::query()->findOrFail($install->input['tool_id']);
    expect($tool->status->value)->toBe('installing')->and($tool->owned)->toBeFalse()
        ->and(app(ToolConvergeIntent::class)->capture($node)[0])->toMatchArray(['id' => $tool->id, 'owned' => false]);

    $retry = $operations->execute('install', $node->id, 'composer', 'laravel/installer', null, $tool);
    expect($retry->input)->toMatchArray(['tool_id' => $tool->id, 'package_owned' => false, 'installed_version' => null])
        ->and(app(ToolIntentProjection::class)->input($retry)['intent']['package_owned'])->toBeFalse();
    $retry->update(['status' => 'cancelled', 'lock_key' => null]);
    $removal = $operations->execute('remove', $node->id, 'composer', 'laravel/installer', null, $tool);
    expect($removal->result['execution'])->toBe('membership_only')->and($tool->fresh())->toBeNull();
})->with(['queued cancellation', 'unreadable result', 'timed out after the lock release']);

it('records ownership only from a successful install, adoption or converged installation', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, version: '1.10');
    policyComplete($install, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    $install->update(['lock_key' => null]);
    expect(Tool::query()->findOrFail($install->input['tool_id'])->owned)->toBeTrue();
    $failed = $operations->execute('install', $node->id, 'apt', 'nano', null, version: '1.10');
    policyComplete($failed, 'failed', ['steps' => ['Apply the package' => ['status' => 'failed']], 'observation' => ['installed_version' => '1.10']]);
    $failed->update(['lock_key' => null]);
    $nano = Tool::query()->findOrFail($failed->input['tool_id']);
    expect($nano->owned)->toBeFalse();
    expect(fn () => $operations->execute('update', $node->id, 'apt', 'nano', null, $nano, version: '1.11'))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.state_invalid'));
    $plans = array_column(app(ToolConvergeIntent::class)->capture($node), null, 'id');
    $observations = app(ToolConvergeObservations::class);
    $observations->record($node->id, $plans, $observations->validate([['id' => $nano->id, 'status' => 'installed', 'installed_version' => '1.10']], $plans, false));
    expect($nano->refresh()->owned)->toBeTrue()
        ->and($operations->execute('remove', $node->id, 'apt', 'nano', null, $nano)->input['package_owned'])->toBeTrue();
});

it('repins surviving members from the source as the Node now defines it when a refused member is removed', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    $tool = policyRefusedInstall($node, $source);
    expect($tool->status->value)->toBe('failed');
    $current = $source;
    if ($case === 'after a sibling moved origin') {
        $current = [...$source, 'uris' => ['https://moved.example.test/apt']];
        AptSourceTrust::record($node->id, $current);
        $nano->update(['desired_repository' => $current]);
    }

    $removal = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    expect($removal->input)->toMatchArray(['package_owned' => false, 'repository' => $current, 'remove_repository' => false, 'repository_packages' => ['nano']]);
})->with(['refused while the source was verified', 'after a sibling moved origin']);

it('cleans a source without a usable package path and keeps the last handle until the source and pins are gone', function (string $failure): void {
    policySwamp();
    $node = policyNode('dev');
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'provisioning']);
    $source = policySignedRepository();
    $tool = policyRefusedInstall($node, $source);
    $handle = AptRepository::query()->where('node_id', $node->id)->where('name', 'proof')->sole();
    expect($handle->verified)->toBeFalse()->and($manager->refresh()->status->value)->toBe('provisioning');
    $operations = app(StartToolOperationAction::class);

    $partial = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    expect($partial->input)->toMatchArray(['package_owned' => false, 'remove_repository' => true, 'repository_id' => $handle->id]);
    policyCleaned($partial, source: $failure !== 'source', pins: $failure !== 'pins');
    expect($tool->refresh()->only(['status', 'error_code']))->toMatchArray(['error_code' => 'tool.repository_failed'])
        ->and($tool->failed_operation)->toBe(ToolOperation::Remove)->and($handle->fresh())->not->toBeNull();

    $complete = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    policyCleaned($complete);
    expect($tool->fresh())->toBeNull()->and($handle->fresh())->toBeNull();
})->with(['source', 'pins']);

it('refuses to rename the source of a first install that may have published it until removal cleans it', function (): void {
    policySwamp();
    $node = policyNode('dev');
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, repository: policySignedRepository(), version: '1.10');
    policyComplete($install, 'failed', ['steps' => ['Write the apt source' => ['status' => 'succeeded'], 'Apply the package' => ['status' => 'failed']]]);
    $install->update(['lock_key' => null]);
    $tool = Tool::query()->findOrFail($install->input['tool_id']);

    expect(fn () => $operations->execute('install', $node->id, 'apt', 'tree', null, $tool, [...policySignedRepository(), 'name' => 'renamed'], '1.10'))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    expect($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool->refresh())->input['repository']['name'])->toBe('proof');
});

it('never restores source trust from an adopted fact that may predate an uncertain write', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $original = policySignedRepository('1.10');
    $body = "\x04".pack('N', 1_700_000_000)."\x16".str_repeat("\x01", 34);
    $keyring = "\xC6".chr(strlen($body)).$body;
    $original['signing_key']['fingerprint'] = strtoupper(sha1("\x99".pack('n', strlen($body)).$body));
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $original]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
    $source = "Types: deb\nURIs: https://example.test/apt\nSuites: 1.10\nComponents: main\nArchitectures: amd64\nSigned-By: /etc/apt/keyrings/orbit-proof.gpg\n";
    NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'packages', 'subject' => 'apt', 'digest' => str_repeat('b', 64),
        'observed_at' => now()->subHour(), 'received_at' => now()->subHour(), 'data' => ['scope' => '/var/lib/dpkg/status',
            'packages' => [['name' => 'tree', 'version' => '1.10']],
            'sources' => [['path' => '/etc/apt/sources.list.d/orbit-proof.sources', 'content' => base64_encode($source)]],
            'keys' => [['path' => '/etc/apt/keyrings/orbit-proof.gpg', 'content' => base64_encode($keyring)]]]]);
    $operations = app(StartToolOperationAction::class);
    $change = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, [...$original, 'suites' => ['1.1']], '1.10');
    $change->update(['status' => 'cancelled', 'lock_key' => null]);

    [$adopted] = $operations->adopt($node->id, 'apt', 'tree', null, $original, null);
    expect($adopted->owned)->toBeTrue()->and($verified->refresh()->verified)->toBeFalse()
        ->and($operations->execute('update', $node->id, 'apt', 'tree', null, $adopted->refresh(), $original, '1.10')->input['repository_given'])->toBeTrue();
});

it('captures each convergence plan with the trust of its source so an unproven source changes the Tools phase', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    policyTool($node, 'tree', '1.10')->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    expect(app(ToolConvergeIntent::class)->capture($node)[0]['repository_verified'])->toBeTrue();
    $record->update(['verified' => false]);
    expect(app(ToolConvergeIntent::class)->capture($node)[0]['repository_verified'])->toBeFalse();
});
