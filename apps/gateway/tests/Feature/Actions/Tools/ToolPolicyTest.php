<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Tools\ManageToolPolicyAction;
use App\Actions\Tools\ScanToolInventoryAction;
use App\Actions\Tools\StartToolConvergeAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptSourceTrust;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolConvergeObservations;
use App\Domain\Tools\ToolConvergeProjection;
use App\Domain\Tools\ToolIntentProjection;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
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
use Illuminate\Support\Facades\Schema;
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

/** A running Tool convergence of the Node, for the observations it records. */
function policyConvergence(Node $node): Operation
{
    return Operation::query()->create(['kind' => 'tool.converge', 'target_type' => 'node_tools', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'lock_key' => 'converge-'.uniqid(), 'input' => ['intent' => ['tools' => []]]]);
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
        $observations->record(policyConvergence($node), $plans, $observations->validate([
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
    policyComplete($install, 'succeeded', ['decision' => ['ready' => true, 'mutate' => true, 'installed_version' => null], 'observation' => ['compliant' => true, 'present' => true, 'installed_version' => '1.10'],
        'steps' => ['Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded']]]);
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
    $results->record(policyConvergence($node), $plans, $failed);
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
    $results->record(policyConvergence($node), $plans, $observed);
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
        $observations->record(policyConvergence($node), $plans, $observations->validate([['id' => $tool->id, 'status' => 'failed', 'installed_version' => null]], $plans, false));
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
    if ($shared) {
        // The refused run may have written the source: repinning the surviving `nano` waits for a write that proves it again.
        expect(fn () => $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool))
            ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_unverified'));
        $nano = Tool::query()->where('node_id', $node->id)->where('package', 'nano')->sole();
        $proof = $operations->execute('update', $node->id, 'apt', 'nano', null, $nano);
        expect($proof->input)->toMatchArray(['repository_given' => true, 'repository_packages' => ['nano']]);
        policyComplete($proof, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
            'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
        $proof->update(['lock_key' => null]);
    }

    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool->refresh());
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
        // Removing the failed member would repin `nano` from a source no write has proven since.
        expect(fn () => $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree->refresh()))
            ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_unverified'));
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

/**
 * The preceding release's own records of each history (tests/Fixtures/preceding-release-tool-histories.json, written by that release's
 * Gateway), migrated by this release's migrations.
 *
 * @return array<string, array{node_id: int, tool_id?: int}>
 */
function policyPrecedingHistories(): array
{
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/preceding-release-tool-histories.json')), true, flags: JSON_THROW_ON_ERROR);
    $ours = require database_path('migrations/2027_03_28_000001_record_tool_ownership_and_source_trust.php');
    $desired = require database_path('migrations/2027_03_27_000000_add_desired_tool_repository.php');
    $ours->down();
    $desired->down();
    foreach (['nodes', 'tool_managers', 'apt_repositories', 'tools', 'operations', 'node_facts'] as $table) {
        $columns = array_flip(Schema::getColumnListing($table));
        foreach ($fixture['tables'][$table] as $row) {
            if ($table !== 'nodes' || $row['id'] !== SwampNodeFixture::Id) {
                DB::table($table)->insert(array_intersect_key($row, $columns));
            }
        }
    }
    $desired->up();
    $ours->up();

    return $fixture['histories'];
}

it('migrates preceding-release receipts by acquisition, sources by writes that ran, and each possibly published source to its own cleanup', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $operations = app(StartToolOperationAction::class);
    $node = static fn (string $history): int => $histories[$history]['node_id'];
    $tool = static fn (string $history, string $package = 'tree', string $manager = 'apt'): ?Tool => Tool::query()->where('node_id', $node($history))
        ->where('package', $package)->whereHas('manager', static fn ($query) => $query->where('name', $manager))->first();
    $source = static fn (string $history, string $name): ?AptRepository => AptRepository::query()->where('node_id', $node($history))->where('name', $name)->first();
    $plans = static fn (string $history): array => array_column(app(ToolConvergeIntent::class)->capture(Node::query()->findOrFail($node($history))), 'repository', 'package');
    $remove = static function (Tool $tool) use ($operations): Operation {
        $operation = $operations->execute('remove', $tool->node_id, $tool->manager->name, $tool->package, null, $tool);
        $operation->update(['status' => 'cancelled', 'lock_key' => null]);

        return $operation;
    };

    // Acquisition grants uninstall authority; an observation or a failure never does, and an adoption the receipts imply keeps it. An
    // interrupted receipt explains the version it recorded (B1j); an adoption to another version than the last recorded one is one (B1k/l).
    // Operations the preceding release left open at the upgrade migrate as they stand: their receipts are read later (B1n-p).
    foreach (['B1n' => false, 'B1o' => false, 'B1p' => true] as $history => $owned) {
        expect($tool($history)->owned)->toBe($owned, $history)->and($histories[$history]['open_input_keys'])->not->toContain('package_owned');
    }
    // A preceding-release removal whose package step failed may have taken the package, as the Gateway now reads such a receipt (B1e).
    // A Gateway expiry with no run never projected the Tool row, so it explains nothing either way (B1m).
    expect($histories['B1m']['update'])->toBe(['failed', 'operation.timed_out', 0, null]);
    expect($histories['B1j']['retry'][0])->toBe('interrupted')->and($histories['B1j']['retry'][1])->toMatchArray(['outcome' => 'unchanged', 'installed_version' => '1.0']);
    foreach (['B1b' => true, 'B1c' => true, 'B1d' => true, 'B1e' => false, 'B1g' => true, 'B1h' => true, 'B1i' => true, 'B1k' => true, 'B1l' => true, 'B1a' => false, 'B1f' => false, 'B1j' => false, 'B1m' => false, 'converged' => false] as $history => $owned) {
        $removal = $remove($tool($history)->refresh());
        expect($tool($history)?->owned ?? false)->toBe($owned, $history)->and($removal->input['package_owned'])->toBe($owned, $history)
            ->and($removal->result['execution'] ?? 'native')->toBe($owned ? 'native' : 'membership_only', $history);
    }
    // A source is trusted only after a write that ran; a skipped write or skipped Tools phase changes nothing.
    foreach (['B8a' => 'adopted', 'B8b' => 'stale', 'B8c' => 'staleskip', 'B8d' => 'moved', 'B6c' => 'shared'] as $history => $name) {
        expect($source($history, $name)->verified)->toBeFalse($history);
    }
    expect($source('B8e', 'steady')->verified)->toBeTrue()->and($source('B6e', 'two')->verified)->toBeTrue();
    // A run that never reached a source write, by the preceding workflows and its own receipt, neither proves nor doubts the source:
    // a skipped publication, a key that never verified, and a decision's refusal of a removal. An interruption with no step may have.
    foreach (['B8f' => true, 'B8g' => true, 'B8h' => true, 'B8j' => true, 'B8i' => false] as $history => $verified) {
        expect($source($history, $histories[$history]['source'])->verified)->toBe($verified, $history);
    }
    expect($histories['B8g']['failed_step'])->toBe('Assert package state')->and($histories['B8h']['failed_step'])->toBe('Fetch the signing key');
    expect($remove($tool('B8g'))->input)->toMatchArray(['remove_repository' => false, 'repository_packages' => ['nano']])
        ->and($remove($tool('B8j'))->input['remove_repository'])->toBeTrue();
    expect(fn () => $operations->execute('remove', $node('B8d'), 'apt', 'tree', null, $tool('B8d')))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_unverified'));
    // Every source that may still be on disk keeps its own cleanup, whatever package names collide; none becomes a membership.
    foreach (['B6b' => 'cancelled', 'B6d' => 'brewname', 'unconfirmed' => 'unconfirmed', 'B6e' => 'one'] as $history => $name) {
        expect(AptSourceTrust::orphans($node($history)))->toBe([$name], $history)->and($source($history, $name)->verified)->toBeFalse($history);
    }
    expect($tool('B6b'))->toBeNull()->and($tool('B6d'))->toBeNull()->and($tool('B6d', 'ripgrep', 'brew')->owned)->toBeTrue()
        ->and($tool('B6e')->desired_repository['name'])->toBe('two')->and($remove($tool('B6e'))->input['repository']['name'])->toBe('two');
    expect($tool('B6a')->desired_repository['name'])->toBe('refresh')->and($remove($tool('B6a'))->input)->toMatchArray(['package_owned' => false, 'remove_repository' => true]);
    // A confirmed removal and a later source-less request win over an earlier possible publication.
    expect(Tool::query()->where('node_id', $node('B7'))->exists())->toBeFalse()
        ->and($plans('B7b'))->toBe(['tree' => null])->and($source('B7b', 'alpha'))->toBeNull()
        ->and($plans('B7c'))->toBe(['tree' => null])->and($plans('B7d'))->toBe(['tree' => null])
        ->and($source('B7c', 'withdrawn-b7c'))->toBeNull()->and($tool('B7d')->owned)->toBeTrue();
    // The withdrawal stands even after another Tool reuses the source name at another URI, so a full convergence still captures.
    expect($plans('B7e'))->toBe(['tree' => null, 'htop' => $source('B7e', 'reused-b7e')->definition])
        ->and($source('B7e', 'reused-b7e')->definition['uris'])->toBe(['https://example.test/reused-b7e-elsewhere']);
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
    $written = $install->input['repository_given'] ? 'succeeded' : 'skipped';
    policyComplete($install, 'failed', ['decision' => ['error_code' => 'tool.package_already_installed', 'installed_version' => '1.10'],
        'steps' => ['Pin repository packages' => ['status' => 'succeeded'], 'Publish the signing key' => ['status' => $written],
            'Write the apt source' => ['status' => $written], 'Prefer the verified package' => ['status' => 'skipped']]]);
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
    policyComplete($install, 'succeeded', ['decision' => ['ready' => true, 'mutate' => true, 'installed_version' => null], 'observation' => ['compliant' => true, 'present' => true, 'installed_version' => '1.10'],
        'steps' => ['Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded']]]);
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
    $observations->record(policyConvergence($node), $plans, $observations->validate([['id' => $nano->id, 'status' => 'installed', 'installed_version' => '1.10']], $plans, false));
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
        AptSourceTrust::claim($node->id, [$current], 1_000_000);
        AptSourceTrust::settle($node->id, 'proof', 1_000_000, 'proved', $current);
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

it('pins only owned members, so a refused package never follows a sibling write or convergence to the vendor', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    $tree = policyRefusedInstall($node, $source);
    expect($tree->owned)->toBeFalse();
    $record->update(['verified' => true]);

    $update = app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'nano', null, $nano);
    expect($update->input['repository_packages'])->toBe(['nano']);
    $update->update(['status' => 'cancelled', 'lock_key' => null]);
    $record->update(['verified' => true]);
    $plans = array_column(app(ToolConvergeIntent::class)->capture($node), 'repository_packages', 'package');
    expect($plans)->toBe(['nano' => ['nano'], 'tree' => ['nano', 'tree']]);
});

it('refuses to repin surviving members from a source an interrupted origin move left unproven', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $origin = policySignedRepository();
    $moved = [...$origin, 'uris' => ['https://moved.example.test/apt']];
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $origin]);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $record->id, 'desired_repository' => $origin]);
    $tree = policyRefusedInstall($node, $origin);
    $record->update(['verified' => true]);
    $operations = app(StartToolOperationAction::class);
    $tree->update(['desired_repository' => $moved]);
    $move = $operations->execute('update', $node->id, 'apt', 'nano', null, $nano, $moved, '1.10');
    expect($move->input)->toMatchArray(['repository' => $moved, 'repository_given' => true]);
    $move->update(['status' => 'cancelled', 'lock_key' => null]);

    expect(fn () => $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree->refresh()))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_unverified'));
    expect($record->refresh()->definition)->toBe($origin)->and($record->verified)->toBeFalse();
});

it('keeps a withdrawn Tool out of convergence while its cleanup is retried', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    $removal = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tree);
    policyComplete($removal, 'succeeded', ['decision' => ['ready' => true, 'remove' => true], 'observation' => ['compliant' => true, 'absent' => true],
        'steps' => ['Remove the package' => ['status' => 'succeeded'], 'Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded'],
            'Remove the apt source' => ['status' => 'succeeded'], 'Remove repository pins' => ['status' => 'failed']]]);
    $removal->update(['lock_key' => null]);
    expect($tree->refresh()->failed_operation)->toBe(ToolOperation::Remove)->and($tree->status->value)->toBe('failed')
        ->and(app(ToolConvergeIntent::class)->capture($node))->toBe([]);
    expect(fn () => app(StartToolConvergeAction::class)->execute($node, 'test', [$tree->id]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.scope_invalid'));
    // The removal confirmed the package gone, so the retry cleans only the source and never touches a replacement package.
    expect(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tree)->input)
        ->toMatchArray(['package_owned' => false, 'remove_repository' => true]);
});

it('binds every unproven source to the converge that captures it, so each converge runs the Tools phase until a write proves it', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    policyTool($node, 'tree', '1.10')->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    $capture = static fn (int $owner): array => array_intersect_key(app(ToolConvergeIntent::class)->capture($node, $owner)[0],
        array_flip(['repository_verified', 'repository_proof_run']));
    expect($capture(41))->toBe(['repository_verified' => true]);
    $record->update(['verified' => false]);
    expect($capture(42))->toBe(['repository_verified' => false, 'repository_proof_run' => 42])
        ->and($capture(43))->toBe(['repository_verified' => false, 'repository_proof_run' => 43]);
});

it('keeps a verified acquisition when a later step fails, and ends uninstall authority once a removal may have taken the package', function (string $case, string $ending): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    if ($case === 'verified install, then a failure') {
        $install = $operations->execute('install', $node->id, 'apt', 'tree', null, version: '1.0');
        // The operator's cancel lands after the package write was verified: the receipt is the same as a failed end check's.
        policyComplete($install, $ending, ['decision' => ['ready' => true, 'mutate' => true], 'observation' => ['compliant' => true, 'present' => true, 'installed_version' => '1.0'],
            'steps' => ['Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => $ending]]]);
        $tool = Tool::query()->findOrFail($install->input['tool_id']);
        expect($tool->only(['owned', 'installed_version']))->toBe(['owned' => true, 'installed_version' => '1.0']);

        return;
    }
    $tool = policyTool($node, 'tree', '1.10');
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    // A package step that ran at all may have removed the package: a nonzero exit is no proof it survived (dpkg can remove it, then fail
    // on another package's trigger).
    $verified = $case !== 'package removal failed';
    $steps = ['Remove the package' => ['status' => $verified ? 'succeeded' : 'failed'], 'Assert package state' => ['status' => $ending],
        ...($verified ? ['Decode package state' => ['status' => 'succeeded']] : [])];
    policyComplete($removal, $ending, ['decision' => ['ready' => true, 'remove' => true], 'observation' => $verified ? ['compliant' => true, 'absent' => true] : [], 'steps' => $steps]);
    $removal->update(['lock_key' => null]);
    expect($tool->refresh()->owned)->toBeFalse()
        ->and($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeFalse();
})->with(['verified install, then a failure', 'package removed, then the survivor repin failed', 'package removal failed'])->with(['failed', 'cancelled']);

it('treats a withdrawn Tool as a removal to finish, not a membership, in Doctor, scan, adoption and export', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['status' => 'failed', 'failed_operation' => 'remove', 'error_code' => 'tool.repository_failed', 'owned' => false, 'installed_version' => null]);
    $fact = NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'packages', 'subject' => 'apt', 'digest' => str_repeat('d', 64),
        'observed_at' => now(), 'received_at' => now(), 'data' => ['scope' => '/var/lib/dpkg/status', 'packages' => []]]);
    $doctor = static fn (): array => app(FactDoctor::class)->report([$node], [DoctorFamily::Tool])->toArray()['nodes'][0]['families'][0];
    expect($doctor()['issues'])->toBe([]);
    $fact->update(['data' => ['scope' => '/var/lib/dpkg/status', 'packages' => [['name' => 'tree', 'version' => '1.10']]]]);
    $scanned = collect(app(ScanToolInventoryAction::class)->execute($node->id)->managers[3]->packages)->firstWhere('package', 'tree');
    expect([$scanned->registered, $scanned->toolId, $scanned->adoptionBlock])->toBe([false, null, 'removal_pending']);
    expect(fn () => app(StartToolOperationAction::class)->adopt($node->id, 'apt', 'tree', null, null, null))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.state_invalid'));
    expect($tree->refresh()->withdrawn())->toBeTrue()->and(app(ToolConvergeIntent::class)->capture($node))->toBe([])
        ->and(app(ManageToolPolicyAction::class)->show('snapshot')['mode'])->toBe('database');
});

it('removes each source no Tool names in a whole convergence and forgets it only after the Tools phase succeeded', function (string $executor, bool $succeeded): void {
    policySwamp();
    $node = policyNode('dev');
    $orphan = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'orphan', 'definition' => [...policySignedRepository(), 'name' => 'orphan', 'uris' => ['https://orphan.example.test/apt']], 'verified' => false]);
    $named = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => policySignedRepository()]);
    policyTool($node, 'nano', '1.10')->update(['apt_repository_id' => $named->id, 'desired_repository' => policySignedRepository()]);
    expect(AptSourceTrust::orphans($node->id))->toBe(['orphan']);
    if ($executor === 'tool.converge') {
        $operation = app(StartToolConvergeAction::class)->execute($node, 'test');
        expect($operation->input['intent']['orphan_sources'])->toBe(['orphan']);
    } else {
        $plans = app(ToolConvergeIntent::class)->capture($node);
        $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id, 'node_id' => $node->id,
            'status' => 'running', 'lock_key' => 'converge-orphans', 'state' => ['swamp_run_id' => 'run'],
            'input' => ['_converge' => ['intent' => ['tools' => $plans, 'orphan_sources' => AptSourceTrust::orphans($node->id)]]]]);
        $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => $succeeded || $name !== 'tools' ? 'succeeded' : 'failed',
            'changed' => 0, 'elapsed_seconds' => 1], ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
        $phases = array_map(static fn (array $phase): array => ! $succeeded && in_array($phase['name'], ['network', 'check'], true) ? [...$phase, 'status' => 'skipped', 'elapsed_seconds' => 0] : $phase, $phases);
        app(NodeConvergeProjection::class)->apply($operation, ['run_id' => 'run', 'status' => $succeeded ? 'succeeded' : 'failed', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['outcome' => $succeeded ? 'succeeded' : 'failed', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')),
                'tools' => $succeeded ? [['id' => $plans[0]['id'], 'status' => 'installed', 'installed_version' => '1.10']] : [], 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []]
                + ($succeeded ? [] : ['failed_phase' => 'tools', 'error_code' => 'operation.native_failed'])]);
        expect([$operation->refresh()->status->value, $operation->error_code])->toBe([$succeeded ? 'succeeded' : 'failed', $succeeded ? null : 'operation.native_failed'])
            ->and($orphan->fresh() === null)->toBe($succeeded)->and($named->fresh())->not->toBeNull();

        return;
    }
    $operation->update(['state' => ['swamp_run_id' => 'run']]);
    app(ToolConvergeProjection::class)->apply($operation, ['run_id' => 'run', 'status' => $succeeded ? 'succeeded' : 'failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['succeeded' => $succeeded, 'changed' => 0, 'cleanup_complete' => true,
            'tools' => $succeeded ? [['id' => Tool::query()->where('package', 'nano')->value('id'), 'status' => 'installed', 'installed_version' => '1.10']] : []]]);
    expect($orphan->fresh() === null)->toBe($succeeded)->and($named->fresh())->not->toBeNull();
})->with(['tool.converge succeeded' => ['tool.converge', true], 'tool.converge failed' => ['tool.converge', false],
    'node.converge Tools succeeded' => ['node.converge', true], 'node.converge Tools failed' => ['node.converge', false]]);

it('proves a shared source again after a refusal that held its preferences without republishing it', function (bool $given): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source, 'verified' => ! $given]);
    policyTool($node, 'nano', '1.10')->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    $install = app(StartToolOperationAction::class)->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10');
    expect($install->input['repository_given'])->toBe($given)->and($record->refresh()->verified)->toBeFalse();
    policyComplete($install, 'failed', ['decision' => ['ready' => false, 'error_code' => 'tool.package_already_installed', 'installed_version' => '1.10'],
        'steps' => ['Pin repository packages' => ['status' => 'succeeded'], 'Publish the signing key' => ['status' => $given ? 'succeeded' : 'skipped'],
            'Write the apt source' => ['status' => $given ? 'succeeded' : 'skipped'], 'Prefer the verified package' => ['status' => 'skipped']]]);
    expect($record->refresh()->verified)->toBe(! $given);
})->with(['verified source, no publication' => [false], 'unverified source republished' => [true]]);

it('verifies a source from convergence only when the whole Tools phase, refusal restores included, succeeded', function (bool $completed): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source, 'verified' => false]);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    $plans = array_column(app(ToolConvergeIntent::class)->capture($node), null, 'id');
    $observations = app(ToolConvergeObservations::class);
    $converge = policyConvergence($node);
    AptSourceTrust::claim($node->id, AptSourceTrust::planned(array_values($plans)), $converge->id);
    $observations->settle($converge, $plans, $observations->validate([['id' => $nano->id, 'status' => 'installed', 'installed_version' => '1.10']], $plans, false),
        $completed ? 'completed' : 'partial', []);
    expect($record->refresh()->verified)->toBe($completed)->and($nano->refresh()->status->value)->toBe('installed');
})->with(['completed' => [true], 'phase failed after this Tool' => [false]]);

it('keeps a source while an enrolled unowned member names it, even after the last owned member goes', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    $tree = policyRefusedInstall($node, $source);
    expect($tree->owned)->toBeFalse()->and($verified->refresh()->verified)->toBeTrue();

    // Ownership decides what apt prefers: no package is left preferred. Membership decides that the source stays.
    $removal = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'nano', null, $nano->refresh());
    expect($removal->input)->toMatchArray(['package_owned' => true, 'repository' => $source, 'remove_repository' => false, 'repository_packages' => []]);
});

it('refuses a second apt source name on a URI and suite another source of the Node uses, on every admission path', function (string $path): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $verified->id, 'desired_repository' => $source]);
    $other = [...$source, 'name' => 'other', 'signing_key' => ['url' => 'https://example.test/other.asc', 'fingerprint' => str_repeat('B', 40)]];
    $operations = app(StartToolOperationAction::class);
    $refused = static fn (callable $admit) => expect($admit)->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    $conflict = static fn (callable $admit) => expect($admit)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));

    match ($path) {
        'install' => $refused(fn () => $operations->execute('install', $node->id, 'apt', 'tree', null, repository: $other, version: '1.10')),
        'update' => $refused(function () use ($node, $operations, $other): void {
            $tree = policyTool($node, 'tree', '1.10');
            $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, $other, '1.10');
        }),
        'adopt' => $refused(fn () => $operations->adopt($node->id, 'apt', 'tree', null, $other, null)),
        'convergence' => $conflict(function () use ($node, $other): void {
            policyTool($node, 'tree', '1.10')->update(['desired_repository' => $other]);
            app(ToolConvergeIntent::class)->capture($node);
        }),
        'mode activation' => $conflict(function () use ($node, $other, $source): void {
            policyTool($node, 'tree', '1.10')->update(['desired_repository' => $other]);
            $entry = static fn (string $package, array $repository): array => ['manager' => 'apt', 'package' => $package, 'version' => '1.10', 'repository' => $repository];
            app(ManageToolPolicyAction::class)->change('file', policyFile([$entry('nano', $source), $entry('tree', $other)]));
        }),
    };
    expect(Operation::query()->where('kind', 'like', 'tool.%')->count())->toBe(0)
        ->and(AptRepository::query()->where('name', 'other')->exists())->toBeFalse();
    // A distinct URI on the same host is a separate source.
    $operations->execute('install', $node->id, 'apt', 'htop', null, repository: [...$other, 'name' => 'third', 'uris' => ['https://example.test/other']], version: '1.10');
})->with(['install', 'update', 'adopt', 'convergence', 'mode activation']);

it('proves a source again after a failure that stopped before any source write, and keeps every origin a failed change may have published', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $original = policySignedRepository();
    $verified = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $original]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
    $operations = app(StartToolOperationAction::class);
    if ($case === 'removal plan refused') {
        policyTool($node, 'nano', '1.10')->update(['apt_repository_id' => $verified->id, 'desired_repository' => $original]);
        $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree);
        expect($verified->refresh()->verified)->toBeFalse();
        policyComplete($removal, 'failed', ['decision' => ['ready' => false, 'error_code' => 'tool.removal_plan_unsafe'],
            'steps' => ['Choose package action' => ['status' => 'succeeded'], 'Remove the package' => ['status' => 'skipped'], 'Remove the apt source' => ['status' => 'skipped'],
                'Remove repository pins' => ['status' => 'skipped'], 'Reconcile remaining repository pins' => ['status' => 'skipped'], 'Assert package state' => ['status' => 'failed']]]);
        expect($verified->refresh()->verified)->toBeTrue();

        return;
    }
    $moved = [...$original, 'uris' => ['https://moved.example.test/apt']];
    $change = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, $moved, '1.10');
    expect($change->input['repository_origins'])->toBe(['example.test', 'moved.example.test'])
        ->and($verified->refresh()->entries)->toBe(['https://example.test/apt 1.10', 'https://moved.example.test/apt 1.10']);
    $published = $case === 'new origin published, then refresh failed';
    policyComplete($change, 'failed', ['steps' => ['Fetch the signing key' => ['status' => $published ? 'succeeded' : 'failed'],
        'Publish the signing key' => ['status' => $published ? 'succeeded' : 'skipped'], 'Pin repository packages' => ['status' => $published ? 'succeeded' : 'skipped'],
        'Write the apt source' => ['status' => $published ? 'succeeded' : 'skipped'], 'Refresh apt source' => ['status' => $published ? 'failed' : 'skipped']]]);
    $change->update(['lock_key' => null]);
    // A key fetch that failed before any write gives back the old definition's trust and entries; the proposed one gains nothing. A
    // published origin stays possible, and the record unproven.
    expect($verified->refresh()->only(['verified', 'entries']))->toBe($published
        ? ['verified' => false, 'entries' => ['https://example.test/apt 1.10', 'https://moved.example.test/apt 1.10']]
        : ['verified' => true, 'entries' => ['https://example.test/apt 1.10']])
        ->and($verified->definition)->toBe($original);

    // Every later write of this source still restricts both origins until Orbit's own write proves one source again.
    $retry = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree->refresh(), $moved, '1.10');
    expect($retry->input['repository_origins'])->toBe(['example.test', 'moved.example.test'])->and($retry->input['repository_given'])->toBeTrue();
    policyComplete($retry, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'], 'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    expect($verified->refresh()->only(['verified', 'entries']))->toBe(['verified' => true, 'entries' => ['https://moved.example.test/apt 1.10']]);
})->with(['removal plan refused', 'key fetch failed', 'new origin published, then refresh failed']);

/** A Swamp socket that answers the run history read with `$run` and every other read with nothing. */
function policySwampSocket(array $run): WebSocketClient
{
    return new class($run) implements WebSocketClient
    {
        private array $frames = [];

        private bool $open = false;

        public function __construct(private readonly array $run) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $replies = array_map(fn (array $frame): array => ['id' => $frame['id'], 'type' => $frame['type'],
                'payload' => match ($frame['type']) {
                    'workflow.history.get' => ['data' => $this->run],
                    // The lookup for a lost start finds no run.
                    'workflow.run.search' => ['data' => ['results' => []], 'total' => 0],
                    default => ['data' => ['results' => []]],
                }], $this->frames);
            $this->frames = [];

            return $replies;
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
}

/**
 * Reads the bound run of a Tool Operation through the terminal reader, as the reconciler does. `$steps` maps each step that ran to its
 * status, and the decision and observation steps to their outputs. The run ends `$late` seconds after the Operation's budget.
 */
function policyReadRun(Operation $operation, string $status, array $steps, int $late, ?array $jobs = null): Operation
{
    // An Operation the Gateway already expired keeps its state; the run is bound when its terminal is read, as a recovered start is.
    $operation->update($operation->status->terminal() ? ['state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-'.$operation->id]]
        : ['state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-'.$operation->id], 'started_at' => now()->subDay(), 'status' => 'running']);
    $deadline = OperationRunBudgets::deadline($operation->refresh());
    $run = ['id' => 'run-'.$operation->id, 'workflowName' => app(DirectOperation::class)->workflowFor($operation), 'status' => $status,
        'startedAt' => $operation->started_at->toIso8601ZuluString(), 'completedAt' => $deadline->addSeconds($late)->toIso8601ZuluString('millisecond'),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'jobs' => $jobs ?? [['name' => 'tools', 'status' => $status, 'steps' => array_map(static fn (string $name, array $step): array => ['name' => $name, 'status' => $step[0],
            ...(isset($step[1]) ? ['outputs' => $step[1]] : [])], array_keys($steps), $steps)]]];
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
    app()->instance(WebSocketClient::class, policySwampSocket($run));

    return app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run['id'], 'status' => $status],
        app(DirectOperation::class)->definition($operation)['terminal_result']);
}

it('keeps what a run that ended after its timeout proved, and lets no unproven removal remove a replacement on retry', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    if (str_starts_with($case, 'install')) {
        $install = $operations->execute('install', $node->id, 'apt', 'tree', null, version: '1.0');
        $status = $case === 'install verified, then the timeout cancelled it' ? 'cancelled' : 'succeeded';
        $read = policyReadRun($install, $status, ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => null]],
            'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.0']],
            'Assert package state' => [$status === 'succeeded' ? 'succeeded' : 'cancelled']], late: 2);
        $tool = Tool::query()->findOrFail($install->input['tool_id']);
        expect([$read->status->value, $read->error_code])->toBe(['failed', 'operation.timed_out'])
            ->and($tool->only(['owned', 'installed_version']))->toBe(['owned' => true, 'installed_version' => '1.0'])
            ->and($tool->status->value)->toBe($status === 'succeeded' ? 'installed' : 'failed');

        return;
    }
    $tool = policyTool($node, 'tree', '1.0');
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    expect($removal->input['package_owned'])->toBeTrue();
    $steps = match ($case) {
        'removal verified absent' => ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]], 'Remove the package' => ['succeeded'],
            'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'absent' => true]], 'Assert package state' => ['cancelled']],
        'removal with no step evidence' => [],
        'removal stopped before its package step' => ['Read installed package' => ['failed'], 'Choose package action' => ['skipped'], 'Remove the package' => ['skipped'],
            'Assert package state' => ['failed']],
    };
    $read = policyReadRun($removal, $steps === [] ? 'failed' : 'cancelled', $steps, late: 2);
    expect([$read->status->value, $read->error_code, $read->lock_key])->toBe(['failed', 'operation.timed_out', null]);
    $retained = $case === 'removal stopped before its package step';
    // The retry removes the package only when a receipt showed the original never left, which also returns the Tool to the state it was
    // admitted from; otherwise it cleans the record and source alone.
    expect($tool->refresh()->owned)->toBe($retained)->and($tool->withdrawn())->toBe(! $retained)
        ->and($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBe($retained);
})->with(['install verified, then the timeout cancelled it', 'install verified and succeeded after its deadline', 'removal verified absent',
    'removal with no step evidence', 'removal stopped before its package step']);

it('releases a removal that timed out with no terminal, so a retry after the hold lapses never removes a replacement', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    $removal = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    $removal->update(['state' => [...($removal->state ?? []), 'swamp_run_id' => 'run'], 'started_at' => now()->subDay(), 'status' => 'running']);
    app(ExpireOperationAction::class)->execute($removal->refresh());
    expect($tool->refresh()->only(['owned', 'error_code']))->toBe(['owned' => false, 'error_code' => 'operation.timed_out']);
    $removal->refresh()->update(['finished_at' => now()->subHours(2)]);
    app(ExpireOperationAction::class)->releaseStaleHolds();
    expect(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeFalse();
});

it('keeps the trust a source had at admission when a run wrote nothing, and never creates it', function (bool $verified): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    if ($verified) {
        AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
        policyTool($node, 'nano', '1.10')->update(['apt_repository_id' => AptRepository::query()->value('id'), 'desired_repository' => $source]);
    }
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10');
    expect($install->input['repository_given'])->toBe(! $verified);
    // A first install stops at its key fetch; a run beside a verified source is refused before any source write.
    policyComplete($install, 'failed', $verified
        ? ['decision' => ['ready' => false, 'error_code' => 'tool.package_already_installed', 'installed_version' => '1.10'], 'steps' => ['Pin repository packages' => ['status' => 'succeeded'],
            'Publish the signing key' => ['status' => 'skipped'], 'Write the apt source' => ['status' => 'skipped'], 'Prefer the verified package' => ['status' => 'skipped']]]
        : ['steps' => ['Fetch the signing key' => ['status' => 'failed'], 'Publish the signing key' => ['status' => 'skipped'], 'Write the apt source' => ['status' => 'skipped'],
            'Prefer the verified package' => ['status' => 'skipped'], 'Assert package state' => ['status' => 'failed']]]);
    $install->update(['lock_key' => null]);
    expect(AptRepository::query()->where('name', 'proof')->value('verified'))->toBe($verified);
    if (! $verified) {
        $retry = $operations->execute('install', $node->id, 'apt', 'tree', null, Tool::query()->findOrFail($install->input['tool_id']));
        expect($retry->input['repository_given'])->toBeTrue();
    }
})->with(['source verified at admission' => [true], 'first publication' => [false]]);

it('refuses a source name whose URI and suite another source may still have on disk after an adoption against an older fact', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $a = policySignedRepository();
    $b = [...$a, 'uris' => ['https://moved.example.test/apt']];
    $tree = policyTool($node, 'tree', '1.10');
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $a]);
    $tree->update(['apt_repository_id' => $record->id, 'desired_repository' => $a]);
    $operations = app(StartToolOperationAction::class);
    $move = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, $b, '1.10');
    policyComplete($move, 'failed', ['steps' => ['Publish the signing key' => ['status' => 'succeeded'], 'Write the apt source' => ['status' => 'succeeded'],
        'Refresh apt source' => ['status' => 'failed']]]);
    $move->update(['lock_key' => null]);
    // A Node agent fact that still shows A: the adoption names A again, but B may be the file on disk.
    AptSourceTrust::adopt($node->id, $a);
    expect($record->refresh()->definition)->toBe($a)->and($record->entries)->toBe(['https://example.test/apt 1.10', 'https://moved.example.test/apt 1.10']);
    expect(fn () => $operations->execute('install', $node->id, 'apt', 'htop', null, repository: [...$b, 'name' => 'other'], version: '1.10'))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
});

it('restricts an IPv6 source origin as apt matches it, without brackets, in the Gateway input and the preferences', function (): void {
    policySwamp();
    $node = policyNode('dev');
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $source = [...policySignedRepository(), 'uris' => ['https://[2001:DB8::7]/apt']];
    $install = app(StartToolOperationAction::class)->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10');
    expect($install->input['repository_origins'])->toBe(['2001:db8::7']);
    $install->update(['status' => 'cancelled', 'lock_key' => null]);
    // apt matches the literal as written, so an uncompressed one stays uncompressed.
    $literal = app(StartToolOperationAction::class)->execute('install', $node->id, 'apt', 'htop', null,
        repository: [...$source, 'name' => 'literal', 'uris' => ['https://[0:0:0:0:0:0:0:1]/apt']], version: '1.10');
    expect($literal->input['repository_origins'])->toBe(['0:0:0:0:0:0:0:1']);
});

it('records a convergence acquisition read after its deadline as on time, without changing the timeout verdict', function (string $kind, int $late): void {
    policySwamp();
    $node = policyNode('dev');
    $tree = policyTool($node, 'tree', '1.0');
    $tree->update(['owned' => false, 'status' => 'failed', 'failed_operation' => 'install', 'installed_version' => null]);
    $plans = app(ToolConvergeIntent::class)->capture($node);
    $observed = [['id' => $tree->id, 'status' => 'installed', 'installed_version' => '1.0']];
    if ($kind === 'tool.converge') {
        $operation = app(StartToolConvergeAction::class)->execute($node, 'test', [$tree->id]);
        $report = ['succeeded' => true, 'changed' => 1, 'tools' => $observed, 'cleanup_complete' => true];
        $jobs = [['name' => 'tools', 'status' => 'succeeded', 'steps' => [['name' => 'Report Tool converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($report)]]]]];
    } else {
        $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id, 'node_id' => $node->id,
            'status' => 'running', 'lock_key' => 'converge-late', 'input' => ['_converge' => ['intent' => ['tools' => $plans, 'orphan_sources' => []]]]]);
        $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => 'succeeded', 'changed' => $name === 'tools' ? 1 : 0, 'elapsed_seconds' => 1],
            ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
        $report = ['outcome' => 'succeeded', 'phases' => $phases, 'changed' => 1, 'elapsed_seconds' => 7, 'tools' => $observed, 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []];
        $jobs = [['name' => 'converge', 'status' => 'succeeded', 'steps' => [['name' => 'Report Node converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($report)]]]]];
    }
    $read = policyReadRun($operation, 'succeeded', [], $late, $jobs);
    expect($read->status->value)->toBe($late > 0 ? 'failed' : 'succeeded')->and($read->error_code)->toBe($late > 0 ? 'operation.timed_out' : null)
        ->and($tree->refresh()->only(['owned', 'installed_version']))->toBe(['owned' => true, 'installed_version' => '1.0'])
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tree)->input['package_owned'])->toBeTrue();
})->with(['tool.converge', 'node.converge'])->with(['two seconds before the deadline' => [-2], 'two seconds after it' => [2]]);

it('never lets a late receipt undo a removal admitted after it', function (): void {
    policySwamp();
    $node = policyNode('dev');
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, version: '1.0');
    $install->update(['state' => [...($install->state ?? []), 'swamp_run_id' => 'run-'.$install->id], 'started_at' => now()->subDay(), 'status' => 'running']);
    app(ExpireOperationAction::class)->execute($install->refresh());
    $install->refresh()->update(['finished_at' => now()->subHours(2)]);
    app(ExpireOperationAction::class)->releaseStaleHolds();
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, Tool::query()->findOrFail($install->input['tool_id']));
    expect($removal->result['execution'] ?? null)->toBe('membership_only')->and(Tool::query()->count())->toBe(0);
    policyReadRun($install->refresh(), 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true]], 'Apply the package' => ['succeeded'],
        'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.0']],
        'Assert package state' => ['succeeded']], late: 2);
    expect(Tool::query()->count())->toBe(0);
});

it('takes uninstall authority from a removal whose start reply was lost, and gives it back only when the receipt shows the package untouched', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    $operations = app(StartToolOperationAction::class);
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    $state = $case === 'start reply lost' || $case === 'start reply lost, then removed without a verified absence'
        ? ['swamp_start' => ['requested_at' => now()->subDay()->toISOString()]] : ['swamp_run_id' => 'run-'.$removal->id];
    $removal->update(['state' => [...($removal->state ?? []), ...$state], 'started_at' => now()->subDay(), 'status' => 'running']);
    app(ExpireOperationAction::class)->execute($removal->refresh());
    expect($tool->refresh()->owned)->toBeFalse();
    $untouched = $case === 'cancellation beat the deadline before the package step';
    match ($case) {
        'start reply lost' => null,
        'start reply lost, then removed without a verified absence' => policyReadRun($removal->refresh(), 'failed', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]],
            'Remove the package' => ['succeeded'], 'Check apt absence' => ['failed'], 'Assert package state' => ['failed']], late: 2),
        'cancellation beat the deadline before the package step' => policyReadRun($removal->refresh(), 'cancelled', ['Read installed package' => ['succeeded'],
            'Choose package action' => ['cancelled'], 'Remove the package' => ['skipped'], 'Assert package state' => ['cancelled']], late: -2),
    };
    if ($untouched) {
        expect($removal->refresh()->status->value)->toBe('cancelled')->and($tool->refresh()->only(['owned', 'error_code']))->toBe(['owned' => true, 'error_code' => null])
            ->and($tool->status->value)->toBe('installed');
    } else {
        $removal->refresh()->update(['finished_at' => now()->subHours(2)]);
        app(ExpireOperationAction::class)->releaseStaleHolds();
    }
    expect($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool->refresh())->input['package_owned'])->toBe($untouched);
})->with(['start reply lost', 'start reply lost, then removed without a verified absence', 'cancellation beat the deadline before the package step']);

it('keeps the trust of a verified source through a cancellation that wrote nothing, and creates none', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    foreach (['tree', 'nano'] as $package) {
        policyTool($node, $package, '1.10')->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    }
    $operations = app(StartToolOperationAction::class);
    $update = $operations->execute('update', $node->id, 'apt', 'tree', null, Tool::query()->where('package', 'tree')->sole(), version: '1.10');
    expect($record->refresh()->verified)->toBeFalse();
    if ($case === 'cancelled before dispatch') {
        app(CancelOperationAction::class)->execute($update);
        expect($record->refresh()->verified)->toBeTrue();
    } else {
        policyComplete($update, 'cancelled', ['steps' => ['Pin repository packages' => ['status' => 'succeeded'], 'Publish the signing key' => ['status' => 'skipped'],
            'Write the apt source' => ['status' => 'skipped'], 'Prefer the verified package' => ['status' => 'skipped']]]);
        $update->update(['lock_key' => null]);
    }
    $sibling = $operations->execute('remove', $node->id, 'apt', 'nano', null, Tool::query()->where('package', 'nano')->sole());
    expect($update->refresh()->status->value)->toBe('cancelled')->and($sibling->input['repository_packages'])->toBe(['tree']);
    $sibling->update(['status' => 'cancelled', 'lock_key' => null]);
    // A source first published by a cancelled install stays unproven.
    $first = $operations->execute('install', $node->id, 'apt', 'htop', null, repository: [...$source, 'name' => 'fresh', 'uris' => ['https://fresh.example.test/apt']], version: '1.10');
    app(CancelOperationAction::class)->execute($first);
    expect(AptRepository::query()->where('name', 'fresh')->value('verified'))->toBeFalse();
})->with(['cancelled before dispatch', 'cancelled with every source write skipped']);

/** A shared verified source `proof` with owned `tree` and `nano`, as a Node records it. */
function policySharedSource(Node $node): AptRepository
{
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    foreach (['tree', 'nano'] as $package) {
        policyTool($node, $package, '1.10')->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    }

    return $record;
}

/** A full Node converge admitted as StartNodeConvergeAction admits it: the plans, the sources' admitted trust, then their suspicion. */
function policyNodeConverge(Node $node): Operation
{
    $plans = app(ToolConvergeIntent::class)->capture($node);
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'queued', 'lock_key' => 'converge-'.uniqid(), 'input' => ['_converge' => ['intent' => ['tools' => $plans, 'orphan_sources' => []]]]]);
    AptSourceTrust::claim($node->id, AptSourceTrust::planned($plans), $operation->id);

    return $operation;
}

it('lets a late convergence report prove what a finished Tools phase wrote and give back what a skipped one left, but not a partial one', function (string $kind, string $phase): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $tree = Tool::query()->where('package', 'tree')->sole();
    $nano = Tool::query()->where('package', 'nano')->sole();
    $operation = $kind === 'tool.converge' ? app(StartToolConvergeAction::class)->execute($node, 'test') : policyNodeConverge($node);
    expect($record->refresh()->verified)->toBeFalse();
    $observed = $phase === 'skipped' ? [] : [['id' => $tree->id, 'status' => 'installed', 'installed_version' => '1.10'],
        ...($phase === 'finished' ? [['id' => $nano->id, 'status' => 'installed', 'installed_version' => '1.10']] : [])];
    if ($kind === 'tool.converge') {
        $report = ['succeeded' => $phase === 'finished', 'changed' => 0, 'tools' => $observed, 'cleanup_complete' => true];
        $jobs = [['name' => 'tools', 'status' => $phase === 'finished' ? 'succeeded' : 'failed', 'steps' => [['name' => 'Report Tool converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($report)]]]]];
    } else {
        $tools = match ($phase) {
            'finished' => 'succeeded', 'skipped' => 'skipped', default => 'failed'
        };
        $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => $name === 'tools' ? $tools : ($name === 'check' && $tools === 'failed' ? 'skipped' : 'succeeded'),
            'changed' => 0, 'elapsed_seconds' => $name === 'check' && $tools === 'failed' ? 0 : ($name === 'tools' && $tools === 'skipped' ? 0 : 1)], ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
        $report = ['outcome' => $tools === 'failed' ? 'failed' : 'succeeded', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')),
            'tools' => $observed, 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []] + ($tools === 'failed' ? ['failed_phase' => 'tools', 'error_code' => 'operation.native_failed'] : []);
        $jobs = [['name' => 'converge', 'status' => $tools === 'failed' ? 'failed' : 'succeeded', 'steps' => [['name' => 'Report Node converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($report)]]]]];
    }
    $read = policyReadRun($operation, $phase === 'finished' || $phase === 'skipped' ? 'succeeded' : 'failed', [], 2, $jobs);
    expect([$read->status->value, $read->error_code])->toBe(['failed', 'operation.timed_out'])
        ->and($record->refresh()->verified)->toBe($phase !== 'partial');
    if ($phase !== 'partial') {
        expect(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'nano', null, $nano->refresh())->input['repository_packages'])->toBe(['tree']);
    }
})->with(['tool.converge', 'node.converge'])->with(['finished', 'partial']);

it('lets a late node convergence report whose Tools phase was skipped give back the trust its admission suspended', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $operation = policyNodeConverge($node);
    $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => $name === 'tools' ? 'skipped' : 'succeeded', 'changed' => 0,
        'elapsed_seconds' => $name === 'tools' ? 0 : 1], ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
    $report = ['outcome' => 'succeeded', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => 6, 'tools' => [], 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []];
    policyReadRun($operation, 'succeeded', [], 2, [['name' => 'converge', 'status' => 'succeeded', 'steps' => [['name' => 'Report Node converge', 'status' => 'succeeded',
        'outputs' => ['stdout' => json_encode($report)]]]]]);
    expect($record->refresh()->verified)->toBeTrue();
});

it('lets a stale receipt change only what no newer receipt changed', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $released = static function (Operation $operation): Operation {
        $operation->update(['state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-'.$operation->id], 'started_at' => now()->subDay(), 'status' => 'running']);
        app(ExpireOperationAction::class)->execute($operation->refresh());
        $operation->refresh()->update(['finished_at' => now()->subHours(2)]);
        app(ExpireOperationAction::class)->releaseStaleHolds();

        return $operation->refresh();
    };
    $installed = static fn (string $version): array => ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']],
        'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => $version]],
        'Assert package state' => ['succeeded']];
    if ($case === 'a newer source cleanup cancelled before dispatch') {
        $install = $released($operations->execute('install', $node->id, 'apt', 'tree', null, repository: policySignedRepository(), version: '1.0'));
        $cleanup = $operations->execute('remove', $node->id, 'apt', 'tree', null, Tool::query()->findOrFail($install->input['tool_id']));
        expect($cleanup->input['package_owned'])->toBeFalse();
        app(CancelOperationAction::class)->execute($cleanup);
        policyReadRun($install, 'succeeded', $installed('1.0'), late: 2);
        $tool = Tool::query()->findOrFail($install->input['tool_id']);
        expect($tool->owned)->toBeTrue()->and($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();

        return;
    }
    $tool = policyTool($node, 'tree', '1.0');
    $tool->update(['installed_version' => '1.0', 'desired_version' => '1.0']);
    if ($case === 'a newer removal refused before its package step') {
        $update = $released($operations->execute('update', $node->id, 'apt', 'tree', null, $tool, version: '2.0'));
        $refusal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool->refresh());
        policyComplete($refusal, 'failed', ['decision' => ['ready' => false, 'remove' => false, 'error_code' => 'tool.removal_plan_unsafe'],
            'steps' => ['Choose package action' => ['status' => 'succeeded'], 'Remove the package' => ['status' => 'skipped'], 'Assert package state' => ['status' => 'failed']]]);
        policyReadRun($update, 'succeeded', $installed('2.0'), late: 2);
        expect($tool->refresh()->only(['owned', 'installed_version', 'error_code']))->toBe(['owned' => true, 'installed_version' => '2.0', 'error_code' => 'tool.removal_plan_unsafe']);

        return;
    }
    // An older run times out; a newer update succeeds at 2.0; then the older run's receipt for 1.0 arrives.
    $older = $case === 'an older convergence receipt after a newer update' ? app(StartToolConvergeAction::class)->execute($node, 'test', [$tool->id])
        : $operations->execute('update', $node->id, 'apt', 'tree', null, $tool, version: '1.0');
    $older = $released($older);
    $newer = $operations->execute('update', $node->id, 'apt', 'tree', null, $tool->refresh(), version: '2.0');
    policyComplete($newer, 'succeeded', ['decision' => ['ready' => true, 'mutate' => true, 'installed_version' => '1.0'],
        'observation' => ['compliant' => true, 'present' => true, 'installed_version' => '2.0'], 'steps' => ['Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded']]]);
    $newer->update(['lock_key' => null]);
    if ($older->kind === 'tool.converge') {
        policyReadRun($older, 'succeeded', [], 2, [['name' => 'tools', 'status' => 'succeeded', 'steps' => [['name' => 'Report Tool converge', 'status' => 'succeeded',
            'outputs' => ['stdout' => json_encode(['succeeded' => true, 'changed' => 0, 'tools' => [['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.0']], 'cleanup_complete' => true])]]]]]);
    } else {
        policyReadRun($older, 'succeeded', $installed('1.0'), late: 2);
    }
    expect($tool->refresh()->only(['desired_version', 'installed_version', 'status']))->toBe(['desired_version' => '2.0', 'installed_version' => '2.0', 'status' => ToolStatus::Installed])
        ->and(app(ToolConvergeIntent::class)->capture($node)[0]['desired_version'])->toBe('2.0');
})->with(['a newer source cleanup cancelled before dispatch', 'a newer removal refused before its package step', 'an older convergence receipt after a newer update',
    'an older direct receipt after a newer update']);

it('lets an older run that wrote nothing give back no trust a newer admission may since have changed', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $tree = Tool::query()->where('package', 'tree')->sole();
    $operations = app(StartToolOperationAction::class);
    $older = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, version: '1.10');
    $older->update(['lock_key' => null, 'status' => 'running', 'state' => ['swamp_run_id' => 'run-'.$older->id]]);
    $moved = [...policySignedRepository(), 'uris' => ['https://moved.example.test/apt']];
    Operation::query()->whereKey($older->id)->update(['lock_key' => null]);
    $newer = Operation::query()->create(['kind' => 'tool.update', 'target_type' => 'node_tools', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'failed',
        'error_code' => 'tool.repository_unavailable', 'input' => ['tool_id' => $tree->id, 'repository' => $moved]]);
    // A newer publication of B, admitted after the older run, failed at refresh: B may be on disk.
    AptSourceTrust::claim($node->id, [$moved], $newer->id);
    policyComplete($older, 'cancelled', ['steps' => ['Pin repository packages' => ['status' => 'succeeded'], 'Publish the signing key' => ['status' => 'skipped'],
        'Write the apt source' => ['status' => 'skipped'], 'Prefer the verified package' => ['status' => 'skipped']]]);
    $older->update(['lock_key' => null]);
    expect($record->refresh()->verified)->toBeFalse()
        ->and($operations->execute('update', $node->id, 'apt', 'tree', null, $tree->refresh(), policySignedRepository(), '1.10')->input['repository_given'])->toBeTrue();
});

it('gives a removal back the Tool it was admitted for when Swamp verifies its lost start never ran', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    $operations = app(StartToolOperationAction::class);
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    $removal->update(['state' => [...($removal->state ?? []), 'swamp_start' => ['key' => 'operation:'.$removal->id, 'workflow' => 'operation-tool-remove-209']],
        'started_at' => now()->subDay(), 'status' => 'running']);
    app(ExpireOperationAction::class)->execute($removal->refresh());
    expect($tool->refresh()->owned)->toBeFalse();
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
    app()->instance(WebSocketClient::class, policySwampSocket([]));
    app(SwampCommandStep::class)->recoverUnbound($removal->refresh());
    expect($removal->refresh()->state['native_timeout']['no_run_verified_at'] ?? null)->not->toBeNull()->and($removal->lock_key)->toBeNull()
        ->and($tool->refresh()->only(['owned', 'error_code']))->toBe(['owned' => true, 'error_code' => null])->and($tool->status)->toBe(ToolStatus::Installed)
        ->and($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();
});

it('ends uninstall authority when a removal step succeeded but absence was never confirmed, on time or late', function (string $check, int $late): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    $operations = app(StartToolOperationAction::class);
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    policyReadRun($removal, $check === 'cancelled' ? 'cancelled' : 'failed', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]],
        'Remove the package' => ['succeeded'], 'Check apt absence' => [$check], 'Assert package state' => [$check]], $late);
    expect($tool->refresh()->owned)->toBeFalse()
        ->and($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeFalse();
})->with(['failed', 'cancelled'])->with(['on time' => [-2], 'after the deadline' => [2]]);

it('gives every source back its admitted trust when a convergence or a proposed change is cancelled before its run', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    // The file catalog moves the shared source from A to B, the update it proposes is cancelled, then the catalog returns to A.
    $entry = static fn (string $package, array $repository): array => ['manager' => 'apt', 'package' => $package, 'version' => '1.10', 'repository' => $repository];
    $moved = [...policySignedRepository(), 'uris' => ['https://moved.example.test/apt']];
    if ($case === 'an update the catalog moved to another definition') {
        $path = policyFile([$entry('nano', policySignedRepository()), $entry('tree', policySignedRepository())]);
        app(ManageToolPolicyAction::class)->change('file', $path);
        policyFile([$entry('nano', $moved), $entry('tree', $moved)], $path);
    }
    $operation = match ($case) {
        'tool:converge' => app(StartToolConvergeAction::class)->execute($node, 'test'),
        'node:converge' => policyNodeConverge($node),
        'an update the catalog moved to another definition' => app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'tree', null, Tool::query()->where('package', 'tree')->sole()),
    };
    expect($record->refresh()->verified)->toBeFalse();
    app(CancelOperationAction::class)->execute($operation);
    if ($case === 'an update the catalog moved to another definition') {
        expect($operation->input['repository'])->toBe($moved);
        policyFile([$entry('nano', policySignedRepository()), $entry('tree', policySignedRepository())], $path);
    }
    expect($operation->refresh()->status->value)->toBe('cancelled')
        ->and($record->refresh()->only(['verified', 'entries', 'definition']))->toBe(['verified' => true, 'entries' => ['https://example.test/apt 1.10'], 'definition' => policySignedRepository()])
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'nano', null, Tool::query()->where('package', 'nano')->sole())->input['repository_packages'])->toBe(['tree']);
})->with(['tool:converge', 'node:converge', 'an update the catalog moved to another definition']);

/** Expires a bound run and lets its target hold lapse, as a run lost past its budget leaves it. */
function policyLapsed(Operation $operation): Operation
{
    $operation->update(['state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-'.$operation->id], 'started_at' => now()->subDay(), 'status' => 'running']);
    app(ExpireOperationAction::class)->execute($operation->refresh());
    $operation->refresh()->update(['finished_at' => now()->subHours(2)]);
    app(ExpireOperationAction::class)->releaseStaleHolds();

    return $operation->refresh();
}

/** A Tools-phase report: tool.converge's, or node.converge's with the Tools phase status given. */
function policyConvergeJobs(Operation $operation, array $observed, string $tools = 'succeeded'): array
{
    if ($operation->kind === 'tool.converge') {
        return [['name' => 'tools', 'status' => $tools === 'succeeded' ? 'succeeded' : 'failed', 'steps' => [
            ['name' => 'Converge tools', 'status' => $tools === 'skipped' ? 'skipped' : ($tools === 'succeeded' ? 'succeeded' : 'failed')],
            ['name' => 'Report Tool converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode(['succeeded' => $tools === 'succeeded', 'changed' => 0,
                'tools' => $observed, 'cleanup_complete' => true])]]]]];
    }
    $after = static fn (string $name): bool => $tools === 'failed' && in_array($name, ['network', 'check'], true);
    $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => $name === 'tools' ? $tools : ($after($name) ? 'skipped' : 'succeeded'),
        'changed' => 0, 'elapsed_seconds' => $after($name) || ($name === 'tools' && $tools === 'skipped') ? 0 : 1], ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
    $report = ['outcome' => $tools === 'failed' ? 'failed' : 'succeeded', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')),
        'tools' => $observed, 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []] + ($tools === 'failed' ? ['failed_phase' => 'tools', 'error_code' => 'operation.native_failed'] : []);

    return [['name' => 'converge', 'status' => $tools === 'failed' ? 'failed' : 'succeeded', 'steps' => [['name' => 'Report Node converge', 'status' => 'succeeded',
        'outputs' => ['stdout' => json_encode($report)]]]]];
}

it('never lets a preceding-release receipt read after the upgrade acquire a package Orbit never wrote', function (string $kind): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', null);
    $tool->update(['owned' => false, 'installed_version' => '1.0']);
    // A preceding-release admission: its input and plans carry no ownership, and its run's helper refused no present package.
    if ($kind === 'tool:update') {
        $operation = Operation::query()->create(['kind' => 'tool.update', 'target_type' => 'node_tools', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'running',
            'input' => ['tool_id' => $tool->id, 'manager' => 'apt', 'package' => 'tree', 'tool_status' => 'installed', 'installed_version' => '1.0', 'repository' => null]]);
        policyReadRun($operation, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => false, 'outcome' => 'unchanged', 'installed_version' => '1.0']],
            'Apply the package' => ['skipped'], 'Decode package state' => ['skipped'], 'Assert package state' => ['succeeded']], -2);
    } else {
        $plan = ['id' => $tool->id, 'manager_id' => $tool->tool_manager_id, 'manager' => 'apt', 'package' => 'tree', 'repository' => null, 'version_constraint' => null,
            'installed_version' => '1.0', 'protected' => []];
        $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id, 'node_id' => $node->id,
            'status' => 'running', 'lock_key' => 'converge-old', 'input' => ['_converge' => ['intent' => ['tools' => [$plan], 'orphan_sources' => []]]]]);
        policyReadRun($operation, 'succeeded', [], -2, policyConvergeJobs($operation, [['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.0']]));
    }
    expect($tool->refresh()->owned)->toBeFalse()
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeFalse();
})->with(['tool:update', 'node:converge']);

it('never lets an older partial convergence report undo a newer write that proved the source', function (string $kind): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $tree = Tool::query()->where('package', 'tree')->sole();
    $older = policyLapsed($kind === 'tool.converge' ? app(StartToolConvergeAction::class)->execute($node, 'test') : policyNodeConverge($node));
    $newer = app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'tree', null, $tree, policySignedRepository(), '1.10');
    policyComplete($newer, 'succeeded', ['decision' => ['ready' => true, 'mutate' => false], 'steps' => ['Assert package state' => ['status' => 'succeeded']]]);
    $newer->update(['lock_key' => null]);
    expect($record->refresh()->verified)->toBeTrue();
    policyReadRun($older, 'failed', [], 2, policyConvergeJobs($older, [['id' => $tree->id, 'status' => 'installed', 'installed_version' => '1.10']], 'failed'));
    expect($record->refresh()->verified)->toBeTrue()
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'nano', null, Tool::query()->where('package', 'nano')->sole())->input['repository_packages'])->toBe(['tree']);
})->with(['tool.converge', 'node.converge']);

it('keeps the desired state of the newest admission when an older convergence report arrives after a file convergence and a mode switch', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    $entry = static fn (string $version): array => ['manager' => 'apt', 'package' => 'tree', 'version' => $version];
    $path = policyFile([$entry('1.0')]);
    app(ManageToolPolicyAction::class)->change('file', $path);
    $older = policyLapsed(app(StartToolConvergeAction::class)->execute($node, 'test'));
    policyFile([$entry('2.0')], $path);
    $newer = app(StartToolConvergeAction::class)->execute($node, 'test');
    expect($tool->refresh()->desired_version)->toBe('2.0');
    policyReadRun($newer, 'succeeded', [], -2, policyConvergeJobs($newer, [['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.0']]));
    app(ManageToolPolicyAction::class)->change('database', null);
    policyReadRun($older, 'succeeded', [], 2, policyConvergeJobs($older, [['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.0']]));
    expect($tool->refresh()->only(['desired_version', 'installed_version']))->toBe(['desired_version' => '2.0', 'installed_version' => '2.0'])
        ->and(app(ToolConvergeIntent::class)->capture($node)[0]['desired_version'])->toBe('2.0');
});

it('lets only what a receipt observed or governed change a field or resource after newer work', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    if ($case === 'a refused update repeating its admitted version') {
        $tool = policyTool($node, 'tree', '1.0');
        $older = policyLapsed($operations->execute('update', $node->id, 'apt', 'tree', null, $tool, version: '2.0'));
        $refused = $operations->execute('update', $node->id, 'apt', 'tree', null, $tool->refresh(), version: '2.0');
        // An unusable inventory read: the decision repeats the version it was admitted with.
        policyComplete($refused, 'failed', ['decision' => ['ready' => false, 'mutate' => false, 'error_code' => 'tool.version_probe_failed', 'installed_version' => '1.0'],
            'steps' => ['Choose package action' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'failed']]]);
        $refused->update(['lock_key' => null]);
        policyReadRun($older, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']], 'Apply the package' => ['succeeded'],
            'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '2.0']], 'Assert package state' => ['succeeded']], 2);
        expect($tool->refresh()->installed_version)->toBe('2.0');

        return;
    }
    if ($case === 'a late manager setup failure after the manager was proven active') {
        $install = policyLapsed($operations->execute('install', $node->id, 'composer', 'laravel/installer', null, version: '5.18.0'));
        $manager = ToolManagerRecord::query()->where('name', 'composer')->sole();
        $proof = $operations->execute('install', $node->id, 'composer', 'laravel/pint', null, version: '1.0.0');
        policyComplete($proof, 'failed', ['decision' => ['ready' => false, 'error_code' => 'tool.version_probe_failed', 'manager_version' => 'Composer 2.8.0'],
            'steps' => ['Assert package state' => ['status' => 'failed']]]);
        $proof->update(['lock_key' => null]);
        expect($manager->refresh()->status->value)->toBe('active');
        policyReadRun($install, 'failed', ['Set up the Tool Manager' => ['failed'], 'Assert package state' => ['failed']], 2);
        expect($manager->refresh()->status->value)->toBe('active')
            ->and($operations->execute('remove', $node->id, 'composer', 'laravel/installer', null, Tool::query()->findOrFail($install->input['tool_id']))->status->value)->not->toBe('failed');

        return;
    }
    if ($case === 'a source-only cleanup whose pin removal failed') {
        ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
        $install = policyLapsed($operations->execute('install', $node->id, 'apt', 'tree', null, repository: policySignedRepository(), version: '1.0'));
        $tool = Tool::query()->findOrFail($install->input['tool_id']);
        $cleanup = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tool);
        expect($cleanup->input['package_owned'])->toBeFalse();
        policyComplete($cleanup, 'succeeded', ['decision' => ['ready' => true, 'remove' => false], 'steps' => ['Remove the package' => ['status' => 'skipped'],
            'Assert package state' => ['status' => 'succeeded'], 'Remove the apt source' => ['status' => 'succeeded'], 'Remove repository pins' => ['status' => 'failed']]]);
        $cleanup->update(['lock_key' => null]);
        policyReadRun($install, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => null]], 'Apply the package' => ['succeeded'],
            'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.0']], 'Assert package state' => ['succeeded']], 2);
        expect($tool->refresh()->owned)->toBeTrue()
            ->and($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();

        return;
    }
    // A late last-member removal after the source was published again for a new sibling.
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => policySignedRepository()]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $record->id, 'desired_repository' => policySignedRepository()]);
    $removal = policyLapsed($operations->execute('remove', $node->id, 'apt', 'tree', null, $tree));
    $sibling = $operations->execute('install', $node->id, 'apt', 'nano', null, repository: policySignedRepository(), version: '1.10');
    policyComplete($sibling, 'succeeded', ['decision' => ['ready' => true, 'mutate' => true], 'observation' => ['compliant' => true, 'present' => true, 'installed_version' => '1.10'],
        'steps' => ['Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded']]]);
    $sibling->update(['lock_key' => null]);
    policyReadRun($removal, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]], 'Remove the package' => ['succeeded'],
        'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'absent' => true]], 'Assert package state' => ['succeeded'],
        'Remove the apt source' => ['succeeded'], 'Remove repository pins' => ['succeeded']], 2);
    expect(AptRepository::query()->whereKey($record->id)->exists())->toBeTrue()->and($record->refresh()->verified)->toBeTrue();
})->with(['a refused update repeating its admitted version', 'a late manager setup failure after the manager was proven active', 'a source-only cleanup whose pin removal failed',
    'a late last-member removal after the source was published again']);

it('gives a preceding-release removal back its migrated ownership when Swamp verifies its lost start never ran', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    // A preceding-release removal admission: no package_owned in its input.
    $removal = Operation::query()->create(['kind' => 'tool.remove', 'target_type' => 'node_tools', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'running',
        'lock_key' => 'remove-old', 'started_at' => now()->subDay(), 'input' => ['tool_id' => $tool->id, 'manager' => 'apt', 'package' => 'tree', 'tool_status' => 'installed', 'repository' => null],
        'state' => ['swamp_start' => ['key' => 'operation:0', 'workflow' => 'operation-tool-remove-209']]]);
    $removal->update(['state' => ['swamp_start' => ['key' => 'operation:'.$removal->id, 'workflow' => 'operation-tool-remove-209']]]);
    app(ExpireOperationAction::class)->execute($removal->refresh());
    expect($tool->refresh()->owned)->toBeFalse();
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
    app()->instance(WebSocketClient::class, policySwampSocket([]));
    app(SwampCommandStep::class)->recoverUnbound($removal->refresh());
    expect($tool->refresh()->owned)->toBeTrue()->and($tool->status)->toBe(ToolStatus::Installed)
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();
});

it('gives back trust only to the definition it proved, and lets a newer request that wrote nothing hide no older proof', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $tree = Tool::query()->where('package', 'tree')->sole();
    $nano = Tool::query()->where('package', 'nano')->sole();
    $operations = app(StartToolOperationAction::class);
    $moved = [...policySignedRepository(), 'uris' => ['https://moved.example.test/apt']];
    if ($case === 'an adoption of B after an older run that wrote nothing') {
        $older = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree, version: '1.10');
        $older->update(['lock_key' => null]);
        AptSourceTrust::adopt($node->id, $moved);
        policyComplete($older, 'cancelled', ['steps' => ['Pin repository packages' => ['status' => 'succeeded'], 'Publish the signing key' => ['status' => 'skipped'],
            'Write the apt source' => ['status' => 'skipped'], 'Prefer the verified package' => ['status' => 'skipped']]]);
        expect($record->refresh()->verified)->toBeFalse()->and($record->definition)->toBe($moved);

        return;
    }
    $older = policyLapsed($operations->execute('update', $node->id, 'apt', 'tree', null, $tree, policySignedRepository(), '1.10'));
    $newer = $operations->execute('update', $node->id, 'apt', 'nano', null, $nano, policySignedRepository(), '1.10');
    if ($case === 'a newer update cancelled before dispatch') {
        app(CancelOperationAction::class)->execute($newer);
    } else {
        policyComplete($newer, 'failed', ['steps' => ['Fetch the signing key' => ['status' => 'failed'], 'Publish the signing key' => ['status' => 'skipped'],
            'Write the apt source' => ['status' => 'skipped'], 'Prefer the verified package' => ['status' => 'skipped'], 'Assert package state' => ['status' => 'failed']]]);
        $newer->update(['lock_key' => null]);
    }
    policyReadRun($older, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => false]], 'Publish the signing key' => ['succeeded'],
        'Write the apt source' => ['succeeded'], 'Prefer the verified package' => ['succeeded'], 'Assert package state' => ['succeeded']], 2);
    expect($record->refresh()->verified)->toBeTrue()
        ->and($operations->execute('remove', $node->id, 'apt', 'nano', null, $nano->refresh())->input['repository_packages'])->toBe(['tree']);
})->with(['an adoption of B after an older run that wrote nothing', 'a newer update cancelled before dispatch', 'a newer update that failed at its key fetch']);

it('gives back trust when a bound convergence is cancelled with its Tools step recorded skipped, on time or late, and not without that evidence', function (string $kind, int $late, bool $skipped): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $operation = $kind === 'tool.converge' ? app(StartToolConvergeAction::class)->execute($node, 'test') : policyNodeConverge($node);
    $jobs = [['name' => 'tools', 'status' => 'cancelled', 'steps' => $skipped ? [['name' => 'Converge tools', 'status' => 'skipped']] : []]];
    policyReadRun($operation, 'cancelled', [], $late, $jobs);
    expect($operation->refresh()->error_code === 'operation.timed_out')->toBe($late > 0)->and($record->refresh()->verified)->toBe($skipped);
})->with(['tool.converge', 'node.converge'])->with(['on time' => [-2], 'late' => [2]])->with(['skipped' => [true], 'no evidence' => [false]]);

it('cancels before its run every Tool request the preceding release never started, so the next request is a current admission', function (string $history, string $verb, Closure $next): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $queued = Operation::query()->findOrFail($h['open']['operation']);
    // Queued, also after Swamp deferred its start: it never ran, wrote nothing, holds nothing, and is never dispatched as admitted.
    expect([$queued->status->value, $queued->error_code, $queued->lock_key, $queued->input['package_owned'] ?? null])->toBe(['cancelled', null, null, null]);
    $tool = Tool::query()->find($h['tool_id']);
    try {
        $next(app(StartToolOperationAction::class)->execute($verb, $h['node_id'], 'apt', $h['package'] ?? 'tree', null, $tool));
    } catch (ToolOperationException $refusal) {
        $next($refusal);
    }
})->with([
    'an unowned tree removal (B1q)' => ['B1q', 'remove', fn () => fn ($next) => expect($next->result['execution'] ?? null)->toBe('membership_only')],
    'an unowned tree update (B1r)' => ['B1r', 'update', fn () => fn ($next) => expect($next->errorCode)->toBe('tool.state_invalid')],
    'a deferred first install of conn-server' => ['D1a', 'install', fn () => fn ($next) => expect($next->errorCode)->toBe('tool.package_protected')],
    'a deferred removal of docker-ce' => ['D1b', 'remove', fn () => fn ($next) => expect($next->result['execution'] ?? null)->toBe('membership_only')],
    'a deferred unowned tree removal' => ['D1c', 'remove', fn () => fn ($next) => expect($next->result['execution'] ?? null)->toBe('membership_only')],
    'a queued update of conn-server' => ['P1a-update', 'update', fn () => fn ($next) => expect($next->errorCode)->toBe('tool.package_protected')],
    'a retry queued after an expired removal released its hold' => ['R1', 'remove', fn () => fn ($next) => expect($next->result['execution'] ?? null)->toBe('membership_only')],
    'an update of an adoption-only source' => ['S7e', 'update', fn () => fn ($next) => expect($next->input['repository_given'])->toBeTrue()],
]);

it('sets aside the authority of a removal the preceding release expired with its run unsettled', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $tool = Tool::query()->findOrFail($histories['B1s']['tool_id']);
    expect($histories['B1s']['open'])->toMatchArray(['status' => 'failed', 'error_code' => 'operation.timed_out'])
        ->and($tool->only(['owned', 'error_code']))->toBe(['owned' => false, 'error_code' => 'operation.timed_out'])
        ->and($tool->receipts['suspended']['owned'] ?? null)->toBeTrue();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    expect(app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeFalse();
});

it('never lets a late receipt of older preceding-release work set back what newer work recorded before the upgrade', function (string $case): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $history = $histories[$case];
    $older = Operation::query()->findOrFail($history['open']['operation']);
    $tool = Tool::query()->findOrFail($history['tool_id']);
    if ($case === 'B3a') {
        $record = AptRepository::query()->where('node_id', $history['node_id'])->where('name', $history['source'])->sole();
        expect($tool->installed_version)->toBe('2.0')->and($record->verified)->toBeTrue()->and($record->definition['uris'])->toBe(['https://example.test/ordered-b3a-b']);
        policyReadRun($older, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']],
            'Publish the signing key' => ['succeeded'], 'Write the apt source' => ['succeeded'], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'],
            'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.5']], 'Assert package state' => ['succeeded']], 2);
        expect($tool->refresh()->installed_version)->toBe('2.0')->and($record->refresh()->definition['uris'])->toBe(['https://example.test/ordered-b3a-b'])
            ->and($record->verified)->toBeTrue();

        return;
    }
    $manager = ToolManagerRecord::query()->where('node_id', $history['node_id'])->where('name', 'composer')->sole();
    expect($manager->status->value)->toBe('active');
    policyReadRun($older, 'failed', ['Set up the Tool Manager' => ['failed'], 'Assert package state' => ['failed']], 2);
    expect($manager->refresh()->status->value)->toBe('active');
})->with(['an older update after a newer one at another source' => ['B3a'], 'an older manager setup failure after a newer proof' => ['B3b']]);

it('settles preceding-release source work read after the upgrade by the source work that ran', function (string $case): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $history = $histories[$case];
    $open = Operation::query()->findOrFail($history['open']['operation']);
    $record = static fn () => AptRepository::query()->where('node_id', $history['node_id'])->where('name', $history['source'])->first();
    if ($case === 'S6d') {
        // A first publication that never started is cancelled before its run: no writer, no record, and the next install publishes.
        $htop = Tool::query()->findOrFail($open->input['tool_id']);
        expect($open->status->value)->toBe('cancelled')->and($record())->toBeNull()
            ->and(app(StartToolOperationAction::class)->execute('install', $history['node_id'], 'apt', 'htop', null, $htop, $open->input['repository'])->input['repository_given'])->toBeTrue();

        return;
    }
    // Open work at the upgrade is an open writer: the source proves nothing until its end.
    expect($record()?->verified ?? false)->toBeFalse();
    match ($case) {
        'S6a' => policyReadRun($open, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => false]], 'Publish the signing key' => ['skipped'],
            'Write the apt source' => ['skipped'], 'Assert package state' => ['succeeded']], -2),
        'S6b' => policyReadRun($open, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]], 'Remove the package' => ['succeeded'],
            'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'absent' => true]], 'Assert package state' => ['succeeded']], -2),
        'S6c' => policyReadRun($open, 'failed', ['Fetch the signing key' => ['failed'], 'Publish the signing key' => ['skipped'], 'Write the apt source' => ['skipped'],
            'Assert package state' => ['failed']], -2),
    };
    // S6a-c wrote no key or source: the source keeps the proof it had.
    expect($record()->verified)->toBeTrue();
    if ($case === 'S6c') {
        expect(app(StartToolOperationAction::class)->execute('remove', $history['node_id'], 'apt', 'nano', null, Tool::query()->where('node_id', $history['node_id'])
            ->where('package', 'nano')->sole())->input['repository_packages'])->toBe(['tree']);
    }
})->with(['an update that skipped publication' => ['S6a'], 'a surviving member removed without a repin step' => ['S6b'],
    'an update that failed at its key fetch' => ['S6c'], 'a queued first publication' => ['S6d']]);

it('never lets a successful write prove a source it did not publish', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source, 'verified' => false]);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    // A preceding-release update admitted with `repository_given: false` against a source the upgrade left unproven.
    $update = Operation::query()->create(['kind' => 'tool.update', 'target_type' => 'node_tools', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'running',
        'input' => ['tool_id' => $tree->id, 'manager' => 'apt', 'package' => 'tree', 'repository' => $source, 'repository_given' => false, 'tool_status' => 'installed']]);
    policyReadRun($update, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => false]], 'Assert package state' => ['succeeded']], -2);
    expect($record->refresh()->verified)->toBeFalse()
        ->and(app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'tree', null, $tree->refresh())->input['repository_given'])->toBeTrue();
});

it('counts every valid observation of a partial convergence, and a checkpointed install of a cancelled one', function (string $kind): void {
    policySwamp();
    $node = policyNode('dev');
    $tree = policyTool($node, 'tree', '1.0');
    $nano = policyTool($node, 'nano', '1.0');
    $nano->update(['owned' => false, 'status' => 'failed', 'failed_operation' => 'install', 'installed_version' => null]);
    $operation = $kind === 'node.converge' ? policyNodeConverge($node) : app(StartToolConvergeAction::class)->execute($node, 'test');
    policyReadRun($operation, 'failed', [], -2, policyConvergeJobs($operation, [['id' => $tree->id, 'status' => 'failed', 'installed_version' => null],
        ['id' => $nano->id, 'status' => 'installed', 'installed_version' => '1.0']], 'failed'));
    expect($tree->refresh()->status->value)->toBe('failed')->and($nano->refresh()->only(['owned', 'installed_version']))->toBe(['owned' => true, 'installed_version' => '1.0']);
    if ($kind === 'tool.converge') {
        $htop = policyTool($node, 'htop', '1.0');
        $htop->update(['owned' => false, 'status' => 'failed', 'failed_operation' => 'install', 'installed_version' => null]);
        Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
        $cancelled = app(StartToolConvergeAction::class)->execute($node, 'test', [$htop->id]);
        $checkpoint = ['succeeded' => false, 'changed' => 1, 'tools' => [['id' => $htop->id, 'status' => 'installed', 'installed_version' => '1.0']], 'cleanup_complete' => true];
        policyReadRun($cancelled, 'cancelled', [], -2, [['name' => 'tools', 'status' => 'cancelled', 'steps' => [['name' => 'Converge tools', 'status' => 'cancelled'],
            ['name' => 'Report Tool converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($checkpoint)]]]]]);
        expect($htop->refresh()->owned)->toBeTrue()
            ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'htop', null, $htop)->input['package_owned'])->toBeTrue();
    }
})->with(['tool.converge', 'node.converge']);

it('records the version a refused decision read, and keeps an adoption over an older late receipt', function (string $case): void {
    policySwamp();
    $node = policyNode('dev');
    $operations = app(StartToolOperationAction::class);
    $tool = policyTool($node, 'tree', null);
    $tool->update(['installed_version' => '1.0']);
    if (str_starts_with($case, 'a refusal')) {
        // A readable inventory that shows the package absent is an observation of absence, never the admitted version again.
        $read = $case === 'a refusal that read the package' ? '2.0' : null;
        $update = $operations->execute('update', $node->id, 'apt', 'tree', null, $tool, version: '3.0');
        policyComplete($update, 'failed', ['decision' => ['ready' => false, 'mutate' => false, 'error_code' => 'tool.version_probe_failed', 'installed_version' => $read,
            'inventory_read' => true], 'steps' => ['Choose package action' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'failed']]]);
        expect($tool->refresh()->installed_version)->toBe($read)->and($update->refresh()->result['installed_version'] ?? null)->toBe($read);

        return;
    }
    $older = policyLapsed($operations->execute('update', $node->id, 'apt', 'tree', null, $tool));
    $keyring = "\xC6".chr(40)."\x04".pack('N', 1_700_000_000)."\x16".str_repeat("\x01", 34);
    NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'packages', 'subject' => 'apt', 'digest' => str_repeat('e', 64), 'observed_at' => now()->subMinute(),
        'received_at' => now()->subMinute(), 'data' => ['scope' => '/var/lib/dpkg/status', 'packages' => [['name' => 'tree', 'version' => '2.0']], 'sources' => [], 'keys' => []]]);
    $tool->update(['status' => 'failed', 'failed_operation' => 'update']);
    $operations->adopt($node->id, 'apt', 'tree', null, null, null);
    expect($tool->refresh()->installed_version)->toBe('2.0');
    policyReadRun($older, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => false, 'installed_version' => '1.0']],
        'Assert package state' => ['succeeded']], 2);
    expect($tool->refresh()->only(['installed_version', 'owned']))->toBe(['installed_version' => '2.0', 'owned' => true]);
})->with(['a refusal that read the package', 'a refusal that read the package absent', 'an adoption after an older run']);

it('keeps the claim when the package survived a failed removal step, and ends it when it may be gone', function (bool $survived): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.0');
    $removal = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool);
    // A held dpkg lock refuses before any write (exit 2), and the package is still there; a trigger failure after removal (exit 1) is not.
    policyReadRun($removal, 'failed', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]], 'Remove the package' => ['failed', ['exitCode' => $survived ? 2 : 1]],
        'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', $survived ? ['compliant' => false, 'present' => true, 'absent' => false, 'installed_version' => '1.0']
            : ['compliant' => true, 'present' => false, 'absent' => true, 'installed_version' => null]], 'Assert package state' => ['failed']], -2);
    expect($tool->refresh()->owned)->toBe($survived)->and($tool->installed_version)->toBe($survived ? '1.0' : null)
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBe($survived);
})->with(['the package survived a refusal before writing' => [true], 'the package is gone' => [false]]);

it('plans the Tools of a convergence the preceding release never started as the current admission does', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $queued = Operation::query()->findOrFail($h['open']['operation']);
    $plans = app(NodeConvergeProjection::class)->input($queued)['intent']['tools'];
    // Its other phases run as admitted; its Tools are the current capture's, each source claimed.
    expect($queued->status->value)->toBe('queued')
        ->and(array_column($plans, 'id'))->toBe(array_column(app(ToolConvergeIntent::class)->capture(Node::query()->findOrFail($h['node_id']), $queued->id), 'id'));
    match ($history) {
        // An adopted tree is owned, so the helper inspects it instead of refusing it, also after Swamp deferred the start.
        'P1b', 'D1d' => expect($plans[0]['owned'])->toBeTrue(),
        // A Tool whose removal is unfinished is no membership: the convergence leaves it out.
        'W1' => expect($h['row'])->toMatchArray(['status' => 'failed', 'failed_operation' => 'remove'])->and($plans)->toBe([]),
        // An owned member of a signed source is preferred by it, and the source has its writer.
        default => expect($plans[0]['repository_packages'])->toBe(['tree'])
            ->and(AptRepository::query()->where('node_id', $h['node_id'])->where('name', 'plan-w2')->sole()->writers)->toHaveKey((string) $queued->id),
    };
})->with(['an adopted tree' => ['P1b'], 'an adopted tree whose start Swamp deferred' => ['D1d'], 'a withdrawn Tool' => ['W1'], 'an owned signed-source Tool' => ['W2']]);

it('reads a removal that timed out under the authority its admission gave it, not the one its timeout set aside', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $tool = Tool::query()->findOrFail($histories['P1c']['tool_id']);
    expect(Operation::query()->findOrFail($histories['P1c']['open']['operation'])->status->value)->toBe('cancelled');
    // The operator's removal is admitted under the claim the upgrade recorded.
    $removal = app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool);
    expect($removal->input['package_owned'])->toBeTrue();
    // The run found tree absent, so it removed nothing; the timeout set aside the claim, and the run was then cancelled.
    $read = policyReadRun($removal, 'cancelled', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => false]], 'Remove the package' => ['skipped'],
        'Assert package state' => ['cancelled']], 2);
    expect($read->error_code)->toBe('operation.timed_out')->and($tool->refresh()->owned)->toBeFalse();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    // Whatever is installed now is not the package Orbit installed: the retry clears the record and never removes it.
    expect(app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->result['execution'] ?? null)->toBe('membership_only');
});

it('orders each field the preceding release changed by the receipt that changed it, an adoption by the admissions that captured it', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $operations = app(StartToolOperationAction::class);
    $tool = Tool::query()->find($h['tool_id']);
    $installed = ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => null]], 'Apply the package' => ['succeeded'],
        'Check apt package' => ['succeeded'], 'Assert package state' => ['succeeded']];
    switch ($history) {
        case 'P3c':
            // A newer no-op observed 2.0 and changed no ownership: the older write that installed it, read late, acquires it.
            expect($tool->owned)->toBeFalse();
            policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', [...$installed,
                'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '2.0']]], 2);
            expect($tool->refresh()->only(['owned', 'installed_version']))->toBe(['owned' => true, 'installed_version' => '2.0'])
                ->and($operations->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();
            break;
        case 'P3d':
            // A newer cancel that read no manager version changed no manager state: the older run's proof, read late, counts.
            $manager = ToolManagerRecord::query()->where('node_id', $h['node_id'])->where('name', 'composer')->sole();
            policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', [...$installed,
                'Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => null, 'manager_version' => 'Composer 2.8.0']],
                'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '5.0.0']]], 2);
            expect($manager->refresh()->status->value)->toBe('active');
            break;
        case 'P3e':
            // The adoption of 2.0 from B came after the update at A was admitted: the update's late success sets back nothing.
            expect($tool->only(['installed_version', 'owned']))->toBe(['installed_version' => '2.0', 'owned' => true]);
            policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', [...$installed, 'Publish the signing key' => ['succeeded'],
                'Write the apt source' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.5']]], 2);
            expect($tool->refresh()->installed_version)->toBe('2.0')->and($tool->aptRepository->name)->toBe($h['adopted_source'])
                ->and($operations->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['repository']['name'] ?? null)->toBe($h['adopted_source']);
            break;
        default:
            // The queued removal never ran; its admission captured the adoption, so a current removal's verified receipt, on time, removes the Tool.
            expect(Operation::query()->findOrFail($h['open']['operation'])->status->value)->toBe('cancelled');
            $removal = $operations->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool);
            policyReadRun($removal, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]], 'Remove the package' => ['succeeded'],
                'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'absent' => true]], 'Assert package state' => ['succeeded']], -2);
            expect([$removal->refresh()->status->value, Tool::query()->whereKey($h['tool_id'])->exists()])->toBe(['succeeded', false]);
    }
})->with(['an older write after a newer no-op' => ['P3c'], 'an older manager proof after a newer cancel' => ['P3d'],
    'an older update after a newer adoption' => ['P3e'], 'a queued removal after an adoption' => ['P3f']]);

it('ends authority after any removal the preceding release may have run, and keeps it only on proof the package was untouched or newer work', function (string $history, bool $owned): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    expect($tool->owned)->toBe($owned);
    if ($history === 'P4a') {
        // The start was sent and its reply lost: the claim waits for the run's end or Swamp's proof that no run exists.
        expect($h['started'])->toBe(['running', 'swamp.start_uncertain', 0, null])->and($tool->receipts['suspended']['owned'] ?? null)->toBeTrue();
    }
    if ($history === 'P4c') {
        // The older removal, read late, never undoes the newer update that installed 2.0.
        policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]],
            'Remove the package' => ['succeeded'], 'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'absent' => true]],
            'Assert package state' => ['succeeded']], 2);
        expect($tool->refresh()->only(['owned', 'installed_version']))->toBe(['owned' => true, 'installed_version' => '2.0']);
    }
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    $retry = app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool->refresh());
    expect($retry->input['package_owned'] ?? false)->toBe($owned);
})->with(['a removal whose start reply was lost' => ['P4a', false], 'a removal that succeeded after its expiry' => ['P4b', false],
    'an expired removal before a newer update' => ['P4c', true], 'a removal cancelled after it found the package absent' => ['P5a', false],
    'a removal whose dpkg step stopped on the lock' => ['P5c', true], 'a removal whose start Swamp refused' => ['R2', true],
    'a removal that removed the package before a trigger hit the lock' => ['L1', false], 'a removal dpkg refused on its database lock' => ['L2', true]]);

it('keeps the proof of a source that preceding-release work, read after the upgrade, never wrote', function (string $case): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $history = $histories[$case];
    $open = Operation::query()->findOrFail($history['open']['operation']);
    $record = static fn () => AptRepository::query()->where('node_id', $history['node_id'])->where('name', $history['source'])->sole();
    // The preceding workflows have no preference or repin step; the steps they have were skipped.
    match ($case) {
        'S6a' => policyReadRun($open, 'failed', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => false]], 'Publish the signing key' => ['skipped'],
            'Pin repository packages' => ['succeeded'], 'Write the apt source' => ['skipped'], 'Refresh apt source' => ['failed'], 'Assert package state' => ['failed']], -2),
        default => policyReadRun($open, 'failed', ['Choose package action' => ['succeeded', ['ready' => false, 'remove' => false, 'error_code' => 'tool.removal_plan_unsafe']],
            'Remove the package' => ['skipped'], 'Remove the apt source' => ['skipped'], 'Remove repository pins' => ['skipped'], 'Assert package state' => ['failed']], -2),
    };
    expect($record()->verified)->toBeTrue();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    // A surviving member's removal repins the other only from a proven source.
    [$removed, $survivor] = $case === 'S6a' ? ['tree', 'nano'] : ['nano', 'tree'];
    expect(app(StartToolOperationAction::class)->execute('remove', $history['node_id'], 'apt', $removed, null, Tool::query()->where('node_id', $history['node_id'])
        ->where('package', $removed)->sole())->input['repository_packages'])->toBe([$survivor]);
})->with(['an update that skipped all publication and failed at refresh' => ['S6a'], 'a refused removal of a surviving member' => ['S6b']]);

it('never brings back a source a newer removal confirmed gone when an older convergence is read late', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $operations = app(StartToolOperationAction::class);
    $tree = policyTool($node, 'tree', '1.10');
    $record = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'proof', 'definition' => $source]);
    $tree->update(['apt_repository_id' => $record->id, 'desired_repository' => $source]);
    $older = policyLapsed(app(StartToolConvergeAction::class)->execute($node, 'test'));
    $removal = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree);
    expect($removal->input['remove_repository'])->toBeTrue();
    policyCleaned($removal);
    expect(AptRepository::query()->whereKey($record->id)->exists())->toBeFalse();
    policyReadRun($older, 'succeeded', [], 2, policyConvergeJobs($older, [['id' => $tree->id, 'status' => 'installed', 'installed_version' => '1.10']]));
    expect(AptRepository::query()->where('node_id', $node->id)->where('name', 'proof')->exists())->toBeFalse();
    // The next install from the source publishes it again.
    expect($operations->execute('install', $node->id, 'apt', 'tree', null, repository: $source, version: '1.10')->input['repository_given'])->toBeTrue();
});

it('orders a version only by a receipt that read it, and a source by the adoption that named it', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    $older = Operation::query()->findOrFail($h['open']['operation']);
    $wrote = static fn (string $version): array => ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']],
        'Publish the signing key' => ['succeeded'], 'Write the apt source' => ['succeeded'], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'],
        'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => $version]], 'Assert package state' => ['succeeded']];
    if ($history === 'V1') {
        // The newer update failed at its key fetch and repeated its admitted version, which it never read: the older run's 1.5 counts.
        expect($h['newer'])->toBe(['failed', 'tool.repository_failed', '1.0', 'Fetch the signing key']);
        policyReadRun($older, 'succeeded', $wrote('1.5'), 2);
        expect($tool->refresh()->installed_version)->toBe('1.5');

        return;
    }
    $record = static fn (string $name) => AptRepository::query()->where('node_id', $h['node_id'])->where('name', $name)->first();
    policyReadRun($older, 'succeeded', $wrote($history === 'V2' ? '1.0' : '1.5'), 2);
    $tool->refresh();
    expect($tool->installed_version)->toBe($history === 'V2' ? '1.0' : '2.0')->and($tool->aptRepository?->name)->toBe($h['adopted_source'])
        ->and($record($h['adopted_source'])->definition['uris'])->toBe($h['adopted_uris'])->and($record($h['adopted_source'])->verified)->toBeFalse();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    // The next removal targets the adopted source.
    expect(app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['repository']['uris'] ?? null)->toBe($h['adopted_uris']);
})->with(['a newer update that failed before reading the package' => ['V1'], 'an adoption at the same version from another source' => ['V2'],
    'an adoption from a source of the same name at another URI' => ['V3']]);

it('replays every source history across the upgrade, keeps a confirmed withdrawal withdrawn, and lets a refused start doubt nothing', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $record = static fn () => AptRepository::query()->where('node_id', $h['node_id'])->where('name', $h['source'])->first();
    $operations = app(StartToolOperationAction::class);
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    switch ($history) {
        case 'S7a':
        case 'S7c':
            // A newer last-member removal confirmed the source gone after an older update expired: no record, and another name may use its URI.
            expect($h['newer'])->toBe(['tool.remove', 'succeeded', true])->and($record())->toBeNull();
            ToolManagerRecord::query()->firstOrCreate(['node_id' => $h['node_id'], 'name' => 'apt'], ['status' => 'active']);
            $source = [...policySignedRepository(), 'name' => 'renamed', 'uris' => $h['uris'], 'suites' => ['stable']];
            expect($operations->execute('install', $h['node_id'], 'apt', 'htop', null, repository: $source, version: '1.10')->input['repository_given'])->toBeTrue();
            break;
        case 'S7b':
            // A newer update failed after publication: the source is uncertain, and its record names every entry it may have.
            expect($record()->verified)->toBeFalse();
            break;
        default:
            // Swamp refused the update's start: nothing ran, so the shared source stays proved and the sibling's removal repins.
            expect($h['update'])->toMatchArray(['status' => 'failed', 'error_code' => 'swamp.start_refused', 'run' => null])->and($record()->verified)->toBeTrue()
                ->and($operations->execute('remove', $h['node_id'], 'apt', 'nano', null, Tool::query()->where('node_id', $h['node_id'])->where('package', 'nano')->sole())
                    ->input['repository_packages'])->toBe(['tree']);
    }
})->with(['an adopted source withdrawn after an expired update' => ['S7a'], 'an adopted source written uncertainly after an expired update' => ['S7b'],
    'a published source withdrawn after an expired update' => ['S7c'], 'a shared source and a refused start' => ['S7d']]);
