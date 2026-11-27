<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Tools\AddAptRepositoryAction;
use App\Actions\Tools\ManageToolPolicyAction;
use App\Actions\Tools\ScanToolInventoryAction;
use App\Actions\Tools\StartToolConvergeAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptRepositoryOutcomes;
use App\Domain\Tools\AptRepositoryProjection;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolConvergeObservations;
use App\Domain\Tools\ToolIntentProjection;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
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

/** The Node's apt repository as its own add left it: ready unless stated. */
function policyRepository(Node $node, ?array $definition = null, string $status = 'ready'): AptRepository
{
    $definition ??= policySignedRepository();

    return AptRepository::query()->create(['node_id' => $node->id, 'name' => $definition['name'], 'definition' => $definition,
        'status' => $status, 'error_code' => $status === 'failed' ? 'tool.repository_failed' : null]);
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

function policyFile(array $packages, ?string $path = null, array $repositories = []): string
{
    $path ??= TestOrbitHome::scratch('tools.yaml');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, json_encode(['schema' => 1, 'repositories' => $repositories, 'packages' => $packages], JSON_THROW_ON_ERROR));

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
    $first->update(['apt_repository_id' => policyRepository($first->node)->id]);
    $second->update(['apt_repository_id' => policyRepository($second->node, policySignedRepository('1.1'))->id]);
    $policy = app(ManageToolPolicyAction::class);

    expect(fn () => $policy->show('catalog'))->toThrow(fn (ResourceOperationException $error) => expect($error->details['reason'])->toBe('per_node_divergence'));
    $path = policyFile([['manager' => 'apt', 'package' => 'nano', 'version' => '1.10', 'repository' => 'proof']], repositories: [policySignedRepository()]);
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

it('keeps the repository a failed install named and retries at the same coordinate', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $repository = policyRepository($node);
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, repository: 'proof', version: '1.10');
    policyComplete($install, 'failed', ['steps' => ['Apply the package' => ['status' => 'failed']]]);

    $membership = app(ManageToolPolicyAction::class)->show('snapshot')['document']['memberships'][0];
    expect($membership)->toMatchArray(['repository' => 'proof', 'desired' => ['version' => '1.10', 'constraint' => null, 'repository' => 'proof']])
        ->and(app(ToolConvergeIntent::class)->capture($node)[0]['repository'])->toBe($repository->definition);
    $retry = $operations->execute('install', $node->id, 'apt', 'tree', null);
    expect($retry->input)->toMatchArray(['repository' => $repository->definition, 'repository_id' => $repository->id, 'desired_version' => '1.10']);
});

it('refuses to switch away from a catalog that clears or renames a membership\'s repository', function (bool $rename): void {
    policySwamp();
    $node = policyNode('dev');
    $tool = policyTool($node, 'tree', '1.10');
    $source = policySignedRepository();
    $repository = policyRepository($node, $source);
    $tool->update(['apt_repository_id' => $repository->id]);
    $entry = ['manager' => 'apt', 'package' => 'tree', 'version' => '1.10'];
    $path = policyFile([[...$entry, 'repository' => 'proof']], repositories: [$source]);
    $policy = app(ManageToolPolicyAction::class);
    $policy->change('file', $path);
    $snapshots = DB::table('tool_policy_snapshots')->count();
    policyFile([$rename ? [...$entry, 'repository' => 'renamed'] : $entry], $path, $rename ? [[...$source, 'name' => 'renamed']] : []);
    expect(fn () => $policy->change('database', null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    expect($policy->show('snapshot')['mode'])->toBe('file')->and($tool->fresh()->apt_repository_id)->toBe($repository->id)
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
    $repository = policyRepository($node);
    $tools = [];
    foreach (['tree', 'nano', 'ed', 'ripgrep'] as $package) {
        $tool = policyTool($node, $package, '1.10');
        $tool->update(['apt_repository_id' => $repository->id]);
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
    // A repository the Node has under another name on the same URI and suite stops the convergence that would publish the catalog's.
    policyRepository($node, [...policySignedRepository(), 'name' => 'elsewhere']);
    policyFile([[...$plain($nano), 'repository' => 'proof'], $plain($ed)], $path, [$source]);
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

it('admits the remaining preference packages for both membership changes and refuses a unilateral repository change', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $source = policySignedRepository();
    $reference = policyRepository($node, $source);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $reference->id]);
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'nano', null, repository: 'proof', version: '1.10');
    expect($install->input)->toMatchArray(['repository' => $source, 'repository_packages' => ['nano', 'tree']]);
    policyComplete($install, 'succeeded', ['decision' => ['ready' => true, 'mutate' => true, 'installed_version' => null], 'observation' => ['compliant' => true, 'present' => true, 'installed_version' => '1.10'],
        'steps' => ['Decode package state' => ['status' => 'succeeded'], 'Assert package state' => ['status' => 'succeeded']]]);
    $update = $operations->execute('update', $node->id, 'apt', 'tree', null, $tree);
    expect($update->input)->toMatchArray(['repository_packages' => ['nano', 'tree']]);
    policyComplete($update, 'succeeded', ['decision' => ['ready' => true], 'observation' => ['installed_version' => '1.10'],
        'steps' => ['Assert package state' => ['status' => 'succeeded']]]);

    $entry = static fn (string $package, string $repository): array => ['manager' => 'apt', 'package' => $package, 'version' => '1.10', 'repository' => $repository];
    $path = policyFile([$entry('tree', 'proof'), $entry('nano', 'proof')], repositories: [$source]);
    app(ManageToolPolicyAction::class)->change('file', $path);
    policyFile([$entry('tree', 'other'), $entry('nano', 'proof')], $path, [$source, [...policySignedRepository('1.1'), 'name' => 'other']]);
    expect(fn () => $operations->execute('update', $node->id, 'apt', 'tree', null, $tree))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    expect(fn () => app(ToolConvergeIntent::class)->capture($node))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.repository_conflict'));
    $remove = $operations->execute('remove', $node->id, 'apt', 'tree', null, $tree);
    expect($remove->input)->toMatchArray(['repository_packages' => ['nano']])->not->toHaveKey('remove_repository');
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

it('refuses a catalog that gives one URI and suite two repository names, or names a repository it does not define', function (array $repositories, string $named): void {
    $path = policyFile([['manager' => 'apt', 'package' => 'tree', 'version' => '1.10', 'repository' => $named]], repositories: $repositories);

    expect(fn () => app(ManageToolPolicyAction::class)->change('file', $path))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.catalog_invalid'));
    expect(app(ManageToolPolicyAction::class)->show()['mode'])->toBe('database');
})->with([
    'one URI and suite under two names' => [[policySignedRepository(), [...policySignedRepository(), 'name' => 'again']], 'proof'],
    'an undefined name' => [[policySignedRepository()], 'missing'],
]);

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

it('forgets a refused first install from a repository and leaves the repository and its other members', function (bool $shared): void {
    policySwamp();
    $node = policyNode('dev');
    $repository = policyRepository($node);
    ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $nano = $shared ? tap(policyTool($node, 'nano', '1.10'))->update(['apt_repository_id' => $repository->id]) : null;
    $operations = app(StartToolOperationAction::class);
    $install = $operations->execute('install', $node->id, 'apt', 'tree', null, repository: 'proof', version: '1.10');
    expect($install->input)->toMatchArray(['repository' => $repository->definition, 'repository_packages' => $shared ? ['nano', 'tree'] : ['tree']]);
    policyComplete($install, 'failed', ['decision' => ['error_code' => 'tool.package_already_installed', 'installed_version' => '1.10']]);
    $install->update(['lock_key' => null]);

    // The write published nothing of the repository, so nothing of it is left to clean: the refused membership goes.
    expect(Tool::query()->whereKey($install->input['tool_id'])->exists())->toBeFalse()
        ->and($repository->fresh()->ready())->toBeTrue()
        ->and($nano?->fresh()->apt_repository_id)->toBe($shared ? $repository->id : null);
    if ($shared) {
        expect($operations->execute('update', $node->id, 'apt', 'nano', null, $nano->fresh())->input['repository_packages'])->toBe(['nano']);
    }
})->with(['last member' => [false], 'shared repository' => [true]]);

/**
 * The preceding release's own records of each history (tests/Fixtures/preceding-release-tool-histories.json, written by that release's
 * Gateway), migrated by this release's migrations.
 *
 * @return array<string, array{node_id: int, tool_id?: int}>
 */
/** @return array{histories: array<string, mixed>, tables: array<string, list<array<string, mixed>>>} */
function policyPrecedingFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/preceding-release-tool-histories.json')), true, flags: JSON_THROW_ON_ERROR);
}

function policyPrecedingHistories(): array
{
    $fixture = policyPrecedingFixture();
    $ours = require database_path('migrations/2027_03_28_000001_record_tool_ownership_and_apt_repository_status.php');
    $ours->down();
    // The preceding release's rows the migration reads, exported from its own callers (.orbit-artifacts/tool-contract/check-3530-history).
    foreach (['nodes', 'tool_managers', 'apt_repositories', 'tools', 'operations'] as $table) {
        $columns = array_flip(Schema::getColumnListing($table));
        foreach ($fixture['tables'][$table] as $row) {
            if ($table !== 'nodes' || $row['id'] !== SwampNodeFixture::Id) {
                DB::table($table)->insert(array_intersect_key($row, $columns));
            }
        }
    }
    $ours->up();

    return $fixture['histories'];
}

it('migrates preceding-release receipts by acquisition and keeps each apt repository with its references, not ready until its own add', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $operations = app(StartToolOperationAction::class);
    $node = static fn (string $history): int => $histories[$history]['node_id'];
    $tool = static fn (string $history, string $package = 'tree', string $manager = 'apt'): ?Tool => Tool::query()->where('node_id', $node($history))
        ->where('package', $package)->whereHas('manager', static fn ($query) => $query->where('name', $manager))->first();
    $remove = static function (Tool $tool) use ($operations): Operation {
        $operation = $operations->execute('remove', $tool->node_id, $tool->manager->name, $tool->package, null, $tool);
        $operation->update(['status' => 'cancelled', 'lock_key' => null]);

        return $operation;
    };

    // Acquisition grants uninstall authority; an observation or a failure never does, and an adoption the receipts imply keeps it. An
    // interrupted receipt explains the version it recorded (B1j); an adoption to another version than the last recorded one is one (B1k/l).
    // Operations the preceding release left open at the upgrade migrate as they stand: their receipts are read later (B1n-p).
    foreach (['B1n' => false, 'B1o' => false, 'B1p' => true] as $history => $owned) {
        expect($tool($history)->owned)->toBe($owned, $history);
    }
    // A preceding-release removal whose package step failed may have taken the package, as the Gateway now reads such a receipt (B1e).
    // A Gateway expiry with no run never projected the Tool row, so it explains nothing either way (B1m).
    foreach (['B1b' => true, 'B1c' => true, 'B1d' => true, 'B1e' => false, 'B1g' => true, 'B1h' => true, 'B1i' => true, 'B1k' => true, 'B1l' => true, 'B1a' => false, 'B1f' => false, 'B1j' => false, 'B1m' => false, 'converged' => false] as $history => $owned) {
        $removal = $remove($tool($history)->refresh());
        expect($tool($history)?->owned ?? false)->toBe($owned, $history)->and($removal->input['package_owned'])->toBe($owned, $history)
            ->and($removal->result['execution'] ?? 'native')->toBe($owned ? 'native' : 'membership_only', $history);
    }
    // Every apt repository of the preceding release keeps its name, definition and Tool references, and waits for its own add: the
    // upgrade reads no history to decide it.
    $fixture = policyPrecedingFixture()['tables'];
    $records = AptRepository::query()->orderBy('id')->get();
    expect($records->map(fn (AptRepository $record): array => [$record->id, $record->name, $record->definition, $record->status->value, $record->error_code])->all())
        ->toBe(array_map(static fn (array $row): array => [$row['id'], $row['name'], json_decode($row['definition'], true), 'failed', 'tool.repository_unconfirmed'], $fixture['apt_repositories']));
    $linked = array_column(array_filter($fixture['tools'], static fn (array $row): bool => ($row['apt_repository_id'] ?? null) !== null), 'apt_repository_id', 'id');
    expect(Tool::query()->whereKey(array_keys($linked))->pluck('apt_repository_id', 'id')->all())->toBe(array_intersect_key($linked, Tool::query()->pluck('id', 'id')->all()));
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

it('pins only owned members, so an unowned package never follows a sibling write or convergence to the vendor', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policyRepository($node);
    $nano = policyTool($node, 'nano', '1.10');
    $nano->update(['apt_repository_id' => $record->id]);
    // A first install whose package write failed: Orbit never acquired the package.
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $record->id, 'owned' => false, 'status' => 'failed', 'installed_version' => null]);

    $update = app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'nano', null, $nano);
    expect($update->input['repository_packages'])->toBe(['nano']);
    $update->update(['status' => 'cancelled', 'lock_key' => null]);
    $plans = array_column(app(ToolConvergeIntent::class)->capture($node), 'repository_packages', 'package');
    expect($plans)->toBe(['nano' => ['nano'], 'tree' => ['nano', 'tree']]);
});

it('keeps a withdrawn Tool out of convergence while its cleanup is retried', function (): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policyRepository($node);
    $tree = policyTool($node, 'tree', '1.10');
    $tree->update(['apt_repository_id' => $record->id]);
    $removal = app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tree);
    policyComplete($removal, 'failed', ['decision' => ['ready' => true, 'remove' => true], 'observation' => ['compliant' => true, 'absent' => true],
        'steps' => ['Remove the package' => ['status' => 'succeeded'], 'Decode package state' => ['status' => 'succeeded'],
            'Reconcile remaining repository pins' => ['status' => 'failed'], 'Assert package state' => ['status' => 'failed']]]);
    $removal->update(['lock_key' => null]);
    expect($tree->refresh()->failed_operation)->toBe(ToolOperation::Remove)->and($tree->status->value)->toBe('failed')
        ->and(app(ToolConvergeIntent::class)->capture($node))->toBe([]);
    expect(fn () => app(StartToolConvergeAction::class)->execute($node, 'test', [$tree->id]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.scope_invalid'));
    // The removal confirmed the package gone, so the retry only repins and never touches a replacement package.
    expect(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tree)->input)
        ->toMatchArray(['package_owned' => false, 'repository_packages' => []]);
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
            'status' => 'running', 'lock_key' => 'converge-late', 'input' => ['_converge' => ['intent' => ['tools' => $plans, 'repositories' => []]]]]);
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
        ? ['swamp_start' => ['key' => 'operation:'.$removal->id, 'workflow' => 'operation-tool-remove-209']] : ['swamp_run_id' => 'run-'.$removal->id];
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
    if ($case === 'start reply lost') {
        // Swamp has not answered whether the start became a run: a retry neither removes nor gives up the package. Its lookup finding no
        // run gives the authority back.
        policyUnsettledRetry($tool);
        policyVerifyNoRun($removal);
        $untouched = true;
    }
    expect($operations->execute('remove', $node->id, 'apt', 'tree', null, $tool->refresh())->input['package_owned'])->toBe($untouched);
})->with(['start reply lost', 'start reply lost, then removed without a verified absence', 'cancellation beat the deadline before the package step']);

/** The Node's apt repository, not ready, that `tree` and `nano` install from. */
function policySharedSource(Node $node): AptRepository
{
    $record = policyRepository($node, status: 'failed');
    foreach (['tree', 'nano'] as $package) {
        policyTool($node, $package, '1.10')->update(['apt_repository_id' => $record->id]);
    }

    return $record;
}

/** A full Node converge admitted with the Tools and repositories StartNodeConvergeAction plans. */
function policyNodeConverge(Node $node): Operation
{
    $intent = app(ToolConvergeIntent::class);
    $plans = $intent->capture($node);

    $repositories = $intent->repositories($plans);
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'queued', 'lock_key' => 'converge-'.uniqid(), 'input' => ['_converge' => ['intent' => ['tools' => $plans, 'repositories' => $repositories]]]]);
    AptRepositoryOutcomes::claim(array_column($repositories, 'id'), $operation->id);

    return $operation;
}

it('records each repository a late convergence report published as its own add, whatever the Tools phase', function (string $kind, string $phase): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $tree = Tool::query()->where('package', 'tree')->sole();
    $nano = Tool::query()->where('package', 'nano')->sole();
    $operation = $kind === 'tool.converge' ? app(StartToolConvergeAction::class)->execute($node, 'test') : policyNodeConverge($node);
    expect($record->refresh()->ready())->toBeFalse()->and($operation->input['intent']['repositories'] ?? $operation->input['_converge']['intent']['repositories'])->toHaveCount(1);
    $published = [['name' => 'proof', 'status' => 'ready', 'error_code' => null]];
    $observed = $phase === 'skipped' ? [] : [['id' => $tree->id, 'status' => 'installed', 'installed_version' => '1.10'],
        ...($phase === 'finished' ? [['id' => $nano->id, 'status' => 'installed', 'installed_version' => '1.10']] : [])];
    if ($kind === 'tool.converge') {
        $report = ['succeeded' => $phase === 'finished', 'changed' => 0, 'tools' => $observed, 'repositories' => $published, 'cleanup_complete' => true];
        $jobs = [['name' => 'tools', 'status' => $phase === 'finished' ? 'succeeded' : 'failed', 'steps' => [['name' => 'Report Tool converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($report)]]]]];
    } else {
        $tools = match ($phase) {
            'finished' => 'succeeded', 'skipped' => 'skipped', default => 'failed'
        };
        $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => $name === 'tools' ? $tools : ($name === 'check' && $tools === 'failed' ? 'skipped' : 'succeeded'),
            'changed' => 0, 'elapsed_seconds' => $name === 'check' && $tools === 'failed' ? 0 : ($name === 'tools' && $tools === 'skipped' ? 0 : 1)], ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
        $report = ['outcome' => $tools === 'failed' ? 'failed' : 'succeeded', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')),
            'tools' => $observed, 'repositories' => $published, 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []] + ($tools === 'failed' ? ['failed_phase' => 'tools', 'error_code' => 'operation.native_failed'] : []);
        $jobs = [['name' => 'converge', 'status' => $tools === 'failed' ? 'failed' : 'succeeded', 'steps' => [['name' => 'Report Node converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($report)]]]]];
    }
    $read = policyReadRun($operation, $phase === 'finished' || $phase === 'skipped' ? 'succeeded' : 'failed', [], 2, $jobs);
    expect([$read->status->value, $read->error_code])->toBe(['failed', 'operation.timed_out'])
        ->and($record->refresh()->ready())->toBeTrue();
    expect(app(StartToolOperationAction::class)->execute('update', $node->id, 'apt', 'nano', null, $nano->refresh())->input['repository_packages'])->toBe(['nano', 'tree']);
})->with(['tool.converge', 'node.converge'])->with(['finished', 'partial']);

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
})->with(['a newer removal refused before its package step', 'an older convergence receipt after a newer update',
    'an older direct receipt after a newer update']);

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

/** Expires a bound run and lets its target hold lapse, as a run lost past its budget leaves it. */
/** The scheduled reconciler's pass, with Swamp answering its lookup of each unbound timed-out Operation's start key: no run exists. */
function policyVerifyNoRun(Operation $operation): void
{
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
    app()->instance(WebSocketClient::class, policySwampSocket([]));
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect(data_get($operation->refresh()->state, 'native_timeout.no_run_verified_at'))->not->toBeNull();
}

/** The removal Swamp has not yet answered for: a retry refuses, neither removing nor giving up the package. */
function policyUnsettledRetry(Tool $tool): void
{
    expect(fn () => app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', $tool->package, null, $tool->refresh()))
        ->toThrow(fn (ToolOperationException $error) => expect($error->errorCode)->toBe('tool.removal_unsettled'));
}

function policyLapsed(Operation $operation): Operation
{
    $operation->update(['state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-'.$operation->id], 'started_at' => now()->subDay(), 'status' => 'running']);
    app(ExpireOperationAction::class)->execute($operation->refresh());
    $operation->refresh()->update(['finished_at' => now()->subHours(2)]);
    app(ExpireOperationAction::class)->releaseStaleHolds();

    return $operation->refresh();
}

/** A Tools-phase report: tool.converge's, or node.converge's with the Tools phase status given. */
function policyConvergeJobs(Operation $operation, array $observed, string $tools = 'succeeded', array $repositories = []): array
{
    if ($operation->kind === 'tool.converge') {
        return [['name' => 'tools', 'status' => $tools === 'succeeded' ? 'succeeded' : 'failed', 'steps' => [
            ['name' => 'Converge tools', 'status' => $tools === 'skipped' ? 'skipped' : ($tools === 'succeeded' ? 'succeeded' : 'failed')],
            ['name' => 'Report Tool converge', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode(['succeeded' => $tools === 'succeeded', 'changed' => 0,
                'tools' => $observed, 'repositories' => $repositories, 'cleanup_complete' => true])]]]]];
    }
    $after = static fn (string $name): bool => $tools === 'failed' && in_array($name, ['network', 'check'], true);
    $phases = array_map(static fn (string $name): array => ['name' => $name, 'status' => $name === 'tools' ? $tools : ($after($name) ? 'skipped' : 'succeeded'),
        'changed' => 0, 'elapsed_seconds' => $after($name) || ($name === 'tools' && $tools === 'skipped') ? 0 : 1], ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check']);
    $report = ['outcome' => $tools === 'failed' ? 'failed' : 'succeeded', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')),
        'tools' => $observed, 'repositories' => $repositories, 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => []] + ($tools === 'failed' ? ['failed_phase' => 'tools', 'error_code' => 'operation.native_failed'] : []);

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
            'status' => 'running', 'lock_key' => 'converge-old', 'input' => ['_converge' => ['intent' => ['tools' => [$plan], 'repositories' => []]]]]);
        policyReadRun($operation, 'succeeded', [], -2, policyConvergeJobs($operation, [['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.0']]));
    }
    expect($tool->refresh()->owned)->toBeFalse()
        ->and(app(StartToolOperationAction::class)->execute('remove', $node->id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeFalse();
})->with(['tool:update', 'node:converge']);

it('never lets an older convergence read late decide a repository that a newer add decided', function (string $kind, bool $added): void {
    policySwamp();
    $node = policyNode('dev');
    $record = policySharedSource($node);
    $tools = Tool::query()->where('node_id', $node->id)->orderBy('id')->get();
    $older = policyLapsed($kind === 'tool.converge' ? app(StartToolConvergeAction::class)->execute($node, 'test') : policyNodeConverge($node));
    $definition = policySignedRepository();
    unset($definition['name']);
    $add = app(AddAptRepositoryAction::class)->execute($node->id, 'proof', $definition, 'request-add');
    $add->update(['state' => ['swamp_run_id' => 'add-run']]);
    $outcome = static fn (bool $ready): array => [['name' => 'proof', 'status' => $ready ? 'ready' : 'failed', 'error_code' => $ready ? null : 'tool.repository_key_mismatch']];
    app(AptRepositoryProjection::class)->apply($add, ['run_id' => 'add-run', 'status' => $added ? 'succeeded' : 'failed', 'started_at' => now()->toAtomString(),
        'finished_at' => now()->toAtomString(), 'result' => ['succeeded' => $added, 'changed' => 0, 'tools' => [], 'repositories' => $outcome($added), 'cleanup_complete' => true]]);
    expect($record->refresh()->status->value)->toBe($added ? 'ready' : 'failed');

    $observed = $tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.10'])->all();
    policyReadRun($older, 'succeeded', [], 2, policyConvergeJobs($older, $observed, 'succeeded', $outcome(! $added)));
    expect($record->refresh()->status->value)->toBe($added ? 'ready' : 'failed');
})->with(['tool.converge', 'node.converge'])->with(['the add succeeded' => [true], 'the add failed' => [false]]);

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
    }
})->with(['a refused update repeating its admitted version', 'a late manager setup failure after the manager was proven active']);

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

it('admits again in place every Tool request the preceding release queued and never started, before its first dispatch', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $before = collect(policyPrecedingFixture()['tables']['operations'])->firstWhere('id', $h['open']['operation']);
    $queued = Operation::query()->findOrFail($h['open']['operation']);
    // The same Operation and idempotency key: a replay of the original request returns this outcome.
    expect([$queued->kind, $queued->idempotency_key])->toBe([$before['kind'], $before['idempotency_key'] ?? null]);
    $tool = Tool::query()->find($h['tool_id'] ?? $queued->input['tool_id']);
    match ($history) {
        // Still-valid intent stays queued with what the current admission gives it.
        'P1c' => expect([$queued->status->value, $queued->input['package_owned'], $queued->lock_key !== null])->toBe(['queued', true, true]),
        // A write from an apt repository waits for that repository's own add, which the upgrade never assumes: an update of a Tool that
        // references an unconfirmed one, and a first install that named one the Node has no record of, whose placeholder goes with it.
        'S7e' => expect([$queued->status->value, $queued->error_code, $queued->lock_key, $tool?->aptRepository?->name])->toBe(['failed', 'tool.repository_not_ready', null, $h['source']]),
        'S6d' => expect([$queued->status->value, $queued->error_code, $queued->lock_key, $tool])->toBe(['failed', 'tool.repository_missing', null, null]),
        // What the current admission records without a run: a membership-only removal that deletes the record.
        'B1q', 'D1b', 'D1c', 'R1' => expect([$queued->status->value, $queued->result['execution'] ?? null, $queued->lock_key, $tool])->toBe(['succeeded', 'membership_only', null, null]),
        // A real refusal ends the Operation with its code, nothing run and nothing held.
        'B1r' => expect([$queued->status->value, $queued->error_code, $queued->lock_key])->toBe(['failed', 'tool.state_invalid', null]),
        default => expect([$queued->status->value, $queued->error_code, $queued->lock_key])->toBe(['failed', 'tool.package_protected', null]),
    };
})->with(['an update from an unconfirmed repository' => ['S7e'], 'an owned tree removal' => ['P1c'], 'a queued first install from a repository the Node lacks' => ['S6d'],
    'an unowned tree removal (B1q)' => ['B1q'], 'a deferred removal of docker-ce' => ['D1b'], 'a deferred unowned tree removal' => ['D1c'],
    'a retry queued after an expired removal released its hold' => ['R1'], 'an unowned tree update (B1r)' => ['B1r'],
    'a deferred first install of conn-server' => ['D1a'], 'a queued update of conn-server' => ['P1a-update']]);

it('sets aside the authority of a removal the preceding release expired with its run unsettled', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $tool = Tool::query()->findOrFail($histories['B1s']['tool_id']);
    expect($tool->only(['owned', 'error_code']))->toBe(['owned' => false, 'error_code' => 'operation.timed_out'])
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
        expect($tool->installed_version)->toBe('2.0')->and($record->definition['uris'])->toBe(['https://example.test/ordered-b3a-b']);
        policyReadRun($older, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']],
            'Publish the signing key' => ['succeeded'], 'Write the apt source' => ['succeeded'], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'],
            'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.5']], 'Assert package state' => ['succeeded']], 2);
        // The older run's report decides neither the version nor the repository, which stays as the upgrade left it.
        expect($tool->refresh()->installed_version)->toBe('2.0')->and($record->refresh()->definition['uris'])->toBe(['https://example.test/ordered-b3a-b'])
            ->and($record->ready())->toBeFalse();

        return;
    }
    $manager = ToolManagerRecord::query()->where('node_id', $history['node_id'])->where('name', 'composer')->sole();
    expect($manager->status->value)->toBe('active');
    policyReadRun($older, 'failed', ['Set up the Tool Manager' => ['failed'], 'Assert package state' => ['failed']], 2);
    expect($manager->refresh()->status->value)->toBe('active');
})->with(['an older update after a newer one at another repository definition' => ['B3a'], 'an older manager setup failure after a newer proof' => ['B3b']]);

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
    // Its other phases run as admitted; its Tools are the current capture's.
    expect($queued->status->value)->toBe('queued')
        ->and(array_column($plans, 'id'))->toBe(array_column(app(ToolConvergeIntent::class)->capture(Node::query()->findOrFail($h['node_id']), $queued->id), 'id'));
    match ($history) {
        // An adopted tree is owned, so the helper inspects it instead of refusing it, also after Swamp deferred the start.
        'P1b', 'D1d' => expect($plans[0]['owned'])->toBeTrue(),
        // A Tool whose removal is unfinished is no membership: the convergence leaves it out.
        'W1' => expect($plans)->toBe([]),
        // An owned member of a repository is preferred by it, and the convergence publishes the repository, whose outcome it decides.
        default => expect($plans[0]['repository_packages'])->toBe(['tree'])
            ->and(array_column(app(NodeConvergeProjection::class)->input($queued)['intent']['repositories'], 'ready'))->toBe([false])
            ->and(AptRepository::query()->where('node_id', $h['node_id'])->where('name', 'plan-w2')->sole()->operation_id)->toBe($queued->id),
    };
})->with(['an adopted tree' => ['P1b'], 'an adopted tree whose start Swamp deferred' => ['D1d'], 'a withdrawn Tool' => ['W1'], 'an owned Tool of a repository' => ['W2']]);

it('reads a removal that timed out under the authority its admission gave it, not the one its timeout set aside', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $tool = Tool::query()->findOrFail($histories['P1c']['tool_id']);
    // The removal the preceding release queued is admitted again under the claim the upgrade recorded, and dispatched as such.
    $removal = Operation::query()->findOrFail($histories['P1c']['open']['operation']);
    expect($removal->status->value)->toBe('queued')->and(app(ToolIntentProjection::class)->input($removal)['intent']['package_owned'])->toBeTrue();
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
            // The queued removal, admitted again, captured the adoption: its verified receipt, on time, removes the Tool.
            $removal = Operation::query()->findOrFail($h['open']['operation']);
            expect($removal->input['package_owned'])->toBeTrue();
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
        // The start was sent and its reply lost: the claim waits for the run's end or Swamp's proof that no run exists, and a retry
        // meanwhile neither removes nor gives up the package. The lookup finding no run gives the authority back.
        expect($tool->receipts['suspended']['owned'] ?? null)->toBeTrue();
        Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
        policyUnsettledRetry($tool);
        policyVerifyNoRun(Operation::query()->findOrFail($h['open']['operation']));
        $owned = true;
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
})->with(['a removal whose start reply was lost, until Swamp finds no run' => ['P4a', false], 'a removal that succeeded after its expiry' => ['P4b', false],
    'an expired removal before a newer update' => ['P4c', true], 'a removal cancelled after it found the package absent' => ['P5a', false],
    'a removal whose dpkg step stopped on the lock' => ['P5c', true], 'a removal whose start Swamp refused' => ['R2', true],
    'a removal that removed the package before a trigger hit the lock' => ['L1', false], 'a removal dpkg refused on its database lock' => ['L2', true]]);

it('orders a version only by a receipt that read it, and a repository by the adoption that named it', function (string $history): void {
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
        policyReadRun($older, 'succeeded', $wrote('1.5'), 2);
        expect($tool->refresh()->installed_version)->toBe('1.5');

        return;
    }
    $record = static fn (string $name) => AptRepository::query()->where('node_id', $h['node_id'])->where('name', $name)->first();
    policyReadRun($older, 'succeeded', $wrote($history === 'V2' ? '1.0' : '1.5'), 2);
    $tool->refresh();
    expect($tool->installed_version)->toBe($history === 'V2' ? '1.0' : '2.0')->and($tool->aptRepository?->name)->toBe($h['adopted_source'])
        ->and($record($h['adopted_source'])->definition['uris'])->toBe($h['adopted_uris'])->and($record($h['adopted_source'])->ready())->toBeFalse();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    // The next removal repins from the adopted repository.
    expect(app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['repository']['uris'] ?? null)->toBe($h['adopted_uris']);
})->with(['a newer update that failed before reading the package' => ['V1'], 'an adoption at the same version from another source' => ['V2'],
    'an adoption from a source of the same name at another URI' => ['V3']]);

it('admits queued work again with the current capture, or ends it with the refusal that capture raises', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $queued = Operation::query()->findOrFail($h['open']['operation']);
    $before = collect(policyPrecedingFixture()['tables']['operations'])->firstWhere('id', $queued->id);
    if ($history === 'K2') {
        // A failed first install whose source the preceding release never recorded has no repository, there as here: the convergence
        // plans it plainly and the recorded repository of its sibling waits for its own add.
        $plans = app(NodeConvergeProjection::class)->input($queued)['intent'];
        expect($queued->status->value)->toBe('queued')
            ->and(array_column($plans['tools'], 'repository', 'package'))->toMatchArray(['sl' => null])
            ->and(array_map(static fn (array $entry): array => [$entry['repository']['name'], $entry['ready']], $plans['repositories']))->toBe([['shared-k2-a', false]]);

        return;
    }
    // The current admission refuses it: it ends with that refusal and its admitted input untouched, so nothing runs and nothing is cleaned.
    // An update naming a source the Node has no record of waits for that repository's add.
    expect([$queued->status->value, $queued->error_code, $queued->lock_key])->toBe(['failed', $history === 'K1' ? 'tool.repository_missing' : 'operation.target_busy', null])
        ->and($queued->input)->toBe(json_decode($before['input'], true));
    if ($history === 'K1') {
        expect(Tool::query()->findOrFail($h['tool_id'])->apt_repository_id)->toBeNull();
    } else {
        expect(Operation::query()->findOrFail($h['running']['operation'])->status->value)->toBe('running');
    }
})->with(['an update to a source the Node has no record of' => ['K1'], 'a convergence with a failed install of an unrecorded source' => ['K2'],
    'a convergence while another Tool run holds the Node' => ['K3']]);

it('orders an adoption before the work that captured it, and a version only by an actual inventory reading', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $open = Operation::query()->findOrFail($h['open']['operation']);
    $wrote = static fn (string $version): array => ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']],
        'Publish the signing key' => ['succeeded'], 'Write the apt source' => ['succeeded'], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'],
        'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => $version]], 'Assert package state' => ['succeeded']];
    switch ($history) {
        case 'A1':
            // The removal was admitted after the adoption: its confirmed removal removes the Tool, and the repository stays for its own removal.
            policyReadRun($open, 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'remove' => true]], 'Remove the package' => ['succeeded'],
                'Check apt absence' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'absent' => true]], 'Remove the apt source' => ['succeeded'],
                'Remove repository pins' => ['succeeded'], 'Assert package state' => ['succeeded']], -2);
            expect(Tool::query()->whereKey($h['tool_id'])->exists())->toBeFalse()
                ->and(AptRepository::query()->where('node_id', $h['node_id'])->where('name', $h['source'])->sole()->ready())->toBeFalse();
            break;
        case 'A2':
            // The preceding update's late success republished the source at a new URI, but no run of this release confirmed it: the record
            // keeps the definition the upgrade found, not ready, until `tool:apt-repository-add` publishes the one the operator gives.
            policyReadRun($open, 'succeeded', $wrote('1.0'), -2);
            $record = AptRepository::query()->where('node_id', $h['node_id'])->where('name', $h['source'])->sole();
            expect($record->definition['uris'])->toBe(['https://example.test/adopted-a2'])->and($record->ready())->toBeFalse()
                ->and(Tool::query()->findOrFail($h['tool_id'])->apt_repository_id)->toBe($record->id);
            break;
        default:
            // A3: the newer refusal read 2.0, which the older late 1.5 never sets back. A4: the newer reinstall reached its write only after
            // reading tree absent; that absence, not the admitted 1.0 its receipt repeats, is the newer reading.
            policyReadRun($open, 'succeeded', $wrote('1.5'), 2);
            expect(Tool::query()->findOrFail($h['tool_id'])->installed_version)->toBe($history === 'A3' ? '2.0' : null);
    }
})->with(['a removal admitted after an adoption' => ['A1'], 'a republishing update admitted after an adoption' => ['A2'],
    'a refused update that read the package' => ['A3'], 'a failed reinstall that recorded its admitted version' => ['A4']]);

it('keeps the authority of a removal the preceding release expired before it ever started', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    $expired = Operation::query()->findOrFail($h['open']['operation']);
    expect([$expired->status->value, $expired->error_code, $expired->attempts, $expired->state['swamp_run_id'] ?? null])->toBe(['failed', 'operation.timed_out', 0, null])
        ->and($tool->owned)->toBeTrue()->and($tool->receipts['suspended'] ?? null)->toBeNull();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    expect(app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();
})->with(['queued, never begun' => ['E1'], 'after Swamp deferred its only start' => ['E2']]);

it('answers a replay of a queued request the preceding release keyed, which the current admission refuses, with that refusal', function (): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories['I2'];
    $stored = collect(policyPrecedingFixture()['tables']['operations'])->firstWhere('id', $h['open']['operation']);
    $replayed = app(OperationIdempotency::class)->under('tool.update', $stored['idempotency_key'], $stored['idempotency_fingerprint'],
        fn (): Operation => app(StartToolOperationAction::class)->execute('update', $h['node_id'], 'apt', 'tree', null, Tool::query()->findOrFail($h['tool_id'])));
    // A valid keyed replay is N1's; this one replays the refusal of an update of a Tool Orbit does not own.
    expect([$replayed->id, $replayed->status->value, $replayed->error_code])->toBe([$stored['id'], 'failed', 'tool.state_invalid']);
});

it('admits queued work again in order, and keeps a full convergence full', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $operations = app(StartToolOperationAction::class);
    $converge = Operation::query()->findOrFail($h[$history === 'N1' ? 'converge' : 'open']['operation']);
    $plans = static fn (): array => array_column($converge->refresh()->input['_converge']['intent']['tools'], 'package');
    switch ($history) {
        case 'N1':
            // The keyed update queued first is admitted first: it names a source the Node has no repository record of, so it ends with that
            // refusal, and a replay of its key returns it. The full convergence queued after it no longer overlaps queued work and stays.
            $update = Operation::query()->findOrFail($h['open']['operation']);
            expect([$update->status->value, $update->error_code, $update->input['repository']['name']])->toBe(['failed', 'tool.repository_missing', $h['source']])
                ->and([$converge->status->value, $plans()])->toBe(['queued', ['tree']]);
            $stored = collect(policyPrecedingFixture()['tables']['operations'])->firstWhere('id', $update->id);
            $replayed = app(OperationIdempotency::class)->under('tool.update', $stored['idempotency_key'], $stored['idempotency_fingerprint'],
                fn (): Operation => $operations->execute('update', $h['node_id'], 'apt', 'tree', null, Tool::query()->findOrFail($h['tool_id']), $h['source']));
            expect([$replayed->id, $replayed->status->value])->toBe([$update->id, 'failed']);
            break;
        case 'N2':
            // Admitted with no Tools, a full convergence is still full: it plans the sl install that failed since.
            expect([$converge->status->value, $converge->input['_converge']['tool_scope'], $plans()])->toBe(['queued', true, ['sl']]);
            break;
        case 'N3':
            // A refused first install leaves no membership: no installing record and no repository record for the source it named.
            $install = Operation::query()->findOrFail($h['open']['operation']);
            expect([$install->status->value, $install->error_code])->toBe(['failed', 'tool.repository_missing'])
                ->and(Tool::query()->whereKey($h['tool_id'])->exists())->toBeFalse()
                ->and(AptRepository::query()->where('node_id', $h['node_id'])->where('name', $h['source'])->exists())->toBeFalse();
            break;
        default:
            // Its only Tool is now protected: it plans none and publishes no repository, yet it records the Tools scope it owns.
            expect([$converge->status->value, $converge->input['_converge']['tool_scope'], $plans(), $converge->input['_converge']['intent']['repositories']])
                ->toBe(['queued', true, [], []]);
    }
})->with(['a keyed update queued before a full convergence' => ['N1'], 'a full convergence admitted with no Tools' => ['N2'],
    'a first install the current admission refuses' => ['N3'], 'a full convergence whose only Tool is now protected' => ['N4']]);

it('orders a version by the reading a decision made and a link by what the Tool itself showed', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $open = Operation::query()->findOrFail($h['open']['operation']);
    $tool = Tool::query()->findOrFail($h['tool_id']);
    $wrote = static fn (string $version): array => ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']],
        'Publish the signing key' => ['succeeded'], 'Write the apt source' => ['succeeded'], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'],
        'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => $version]], 'Assert package state' => ['succeeded']];
    if ($history === 'O4') {
        // The convergence's plan captured the adopted link, so the adoption came first and its report of 1.5 is the newer reading.
        policyReadRun($open, 'succeeded', [], 2, policyConvergeJobs($open, [['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.5']]));
        expect($tool->refresh()->installed_version)->toBe('1.5');

        return;
    }
    // O1: the newer update read 1.0 and refused its candidate; O2: the newer reinstall reached its candidate only after reading tree absent;
    // O3: the older update found a sibling's record, not tree's link, and tree was adopted after it. No older late 1.5 sets either back.
    expect($tool->installed_version)->toBe($history === 'O2' ? null : '1.0');
    policyReadRun($open, 'succeeded', $wrote('1.5'), 2);
    expect($tool->refresh()->installed_version)->toBe($history === 'O2' ? null : '1.0');
})->with(['an update that read the admitted version' => ['O1'], 'a reinstall that read absence' => ['O2'],
    'an older update that found a sibling record' => ['O3'], 'a convergence that captured the adopted link' => ['O4']]);

it('keeps removal authority after a start that provably was never sent, on either side of the upgrade', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    $removal = Operation::query()->findOrFail($h['open']['operation']);
    if ($history === 'Q3') {
        // Queued at the upgrade after one deferral, admitted again, and then expired by the current Gateway before any start.
        expect($removal->status->value)->toBe('queued');
        $this->travelTo(OperationRunBudgets::deadline($removal)->addMinute());
        app(ExpireOperationAction::class)->execute($removal);
        expect($removal->refresh()->error_code)->toBe('operation.timed_out');
    } else {
        // Two `draining` deferrals six seconds apart, then the preceding expiry: the last begin was the one the second deferral answered.
    }
    expect($tool->refresh()->owned)->toBeTrue()->and($tool->receipts['suspended'] ?? null)->toBeNull();
    Operation::query()->whereNotNull('lock_key')->update(['lock_key' => null]);
    expect(app(StartToolOperationAction::class)->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();
})->with(['two deferrals before the upgrade' => ['Q1'], 'one deferral, expired after the upgrade' => ['Q3']]);

it('readmits queued work in queue order so no later plan contradicts earlier work, nor runs a refused membership', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $first = Operation::query()->findOrFail($h[$history === 'F1a' ? 'open' : 'converge']['operation']);
    $second = Operation::query()->findOrFail($h[$history === 'F1a' ? 'converge' : 'open']['operation']);
    $plans = static fn (Operation $converge): array => array_column($converge->input['_converge']['intent']['tools'], 'package');
    switch ($history) {
        case 'F1a':
            // The removal queued first stays queued with its authority; the full convergence queued after it would install what it removes,
            // so it is refused as its own admission would have been.
            expect([$first->status->value, $first->input['package_owned']])->toBe(['queued', true])
                ->and([$second->status->value, $second->error_code])->toBe(['failed', 'operation.target_busy']);
            break;
        case 'F1b':
            // The convergence queued first plans what existed when it was admitted; the later first install, refused, leaves no membership
            // that any Operation could still install.
            expect([$first->status->value, $plans($first)])->toBe(['queued', ['nano']])
                ->and($second->status->value)->toBe('failed')->and(Tool::query()->whereKey($h['tool_id'])->exists())->toBeFalse();
            break;
        default:
            // The convergence queued first stays; the later install from a source whose record the upgrade left unconfirmed waits for that
            // repository's own add, and its refused placeholder goes.
            expect($first->status->value)->toBe('queued')
                ->and([$second->status->value, $second->error_code])->toBe(['failed', 'tool.repository_not_ready'])
                ->and(Tool::query()->whereKey($h['tool_id'])->exists())->toBeFalse();
    }
})->with(['a removal queued before a full convergence' => ['F1a'], 'a full convergence queued before a conflicting first install' => ['F1b'],
    'a full convergence before an install from an unconfirmed repository' => ['F1c']]);

it('orders the installed version only by what a decision read, for preceding receipts read before or after the upgrade', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    $older = Operation::query()->findOrFail($h['open']['operation']);
    $wrote = ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true, 'installed_version' => '1.0']], 'Apply the package' => ['succeeded'],
        'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.5']],
        'Assert package state' => ['succeeded']];
    if ($history !== 'M1') {
        // The newer request, still running at the upgrade, ends with the preceding release's refused decision (no `inventory_read`), as its
        // `decide` returns it: an update read tree present at 1.0; a reinstall reached its candidate only after reading tree absent, and
        // its refusal repeats the admitted 1.0 in both version fields.
        $read = $history === 'M2' ? '1.0' : null;
        policyReadRun(Operation::query()->findOrFail($h['newer']['operation']), 'failed', ['Choose package action' => ['succeeded', ['ready' => false, 'mutate' => false,
            'error_code' => 'tool.candidate_version_unavailable', 'manager_version' => '2.7.14', 'installed_version' => '1.0', 'installed_coordinate' => '1.0',
            'candidate_json' => 'null', 'version' => '']], 'Assert package state' => ['failed']], -2);
        expect($tool->refresh()->installed_version)->toBe($read);
    }
    // M1: the newer unsafe removal's decision returns no version, so the 1.0 its receipt repeats is no reading and the older 1.5 counts.
    policyReadRun($older, 'succeeded', $wrote, 2);
    expect($tool->refresh()->installed_version)->toBe(match ($history) {
        'M1' => '1.5', 'M2' => '1.0', default => null
    });
})->with(['an unsafe removal that returns no version' => ['M1'], 'an update read after the upgrade' => ['M2'], 'a reinstall that read absence, read after the upgrade' => ['M3']]);

it('orders what a preceding decision read when its end check failed decoding after the write, read after the upgrade', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    // The newer request, still running at the upgrade, ends with the preceding release's ready decision and a failed decode of its end
    // check: an update read tree 1.3; a reinstall read tree absent.
    $read = $history === 'M2' ? '1.3' : null;
    policyReadRun(Operation::query()->findOrFail($h['newer']['operation']), 'failed', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true,
        'outcome' => 'applied', 'manager_version' => '2.7.14', 'installed_version' => $read, 'installed_coordinate' => $read, 'candidate_json' => '{"version":"2.0"}',
        'version' => '2.0']], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'], 'Decode package state' => ['failed'],
        'Assert package state' => ['failed']], -2);
    expect($tool->refresh()->installed_version)->toBe($read);
    policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true,
        'installed_version' => '1.0']], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'], 'Decode package state' => ['succeeded',
            ['compliant' => true, 'present' => true, 'installed_version' => '1.5']], 'Assert package state' => ['succeeded']], 2);
    expect($tool->refresh()->installed_version)->toBe($read);
})->with(['an update' => ['M2'], 'a reinstall' => ['M3']]);

it('never lets a removal whose start Swamp has not answered end as a successful non-removal', function (string $history, bool $reacquired): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    $removal = Operation::query()->findOrFail($h['open']['operation']);
    $operations = app(StartToolOperationAction::class);
    if ($history === 'R4') {
        // Queued at the upgrade, admitted again, then expired by the current Gateway, which releases its hold once it lapses.
        $this->travelTo(OperationRunBudgets::deadline($removal)->addMinute());
        app(ExpireOperationAction::class)->execute($removal);
        $this->travel(3)->hours();
        app(ExpireOperationAction::class)->releaseStaleHolds();
    }
    expect($removal->refresh()->lock_key)->toBeNull();
    if ($reacquired) {
        // R5: the newer update reacquired tree before the upgrade. An older suspension never overrides that: the retry removes it natively.
        expect($tool->refresh()->owned)->toBeTrue()
            ->and($operations->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool)->input['package_owned'])->toBeTrue();

        return;
    }
    // A 29-second retry hint put the second begin past the schedule's minimum, so it may have been sent: the claim is set aside, and the
    // released hold proves nothing.
    expect($tool->refresh()->owned)->toBeFalse();
    policyUnsettledRetry($tool);
    // The scheduled reconciler's lookup by start key finds no run: the authority comes back, and the retry removes the package.
    policyVerifyNoRun($removal);
    expect($operations->execute('remove', $tool->node_id, 'apt', 'tree', null, $tool->refresh())->input['package_owned'])->toBeTrue();
})->with(['deferred twice and expired before the upgrade' => ['R3', false], 'deferred twice, expired after the upgrade' => ['R4', false],
    'expired and released, then reacquired by a newer update' => ['R5', true]]);

it('keeps every membership a queued convergence captured, an adopted one included, ahead of later direct work', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $converge = Operation::query()->findOrFail($h['converge']['operation']);
    $direct = Operation::query()->findOrFail($h['open']['operation']);
    // The adopted tree has no Operation that made it a member: the convergence queued first plans it (beside nano, sharing its source, in
    // C1d), and pins that same set. The later direct request on tree is refused, as its own admission would have been.
    $plans = $converge->input['_converge']['intent']['tools'];
    // C1e: tree was adopted after the convergence was queued, and its first direct request, a reinstall, captured it installed: that request
    // made no membership, so the convergence plans tree as a capture admitted now would.
    $shared = in_array($history, ['C1d', 'C1e'], true);
    expect([$converge->status->value, array_column($plans, 'package')])->toBe(['queued', $shared ? ['nano', 'tree'] : ['tree']])
        ->and([$direct->status->value, $direct->error_code])->toBe(['failed', 'operation.target_busy'])
        ->and(Tool::query()->whereKey($h['tool_id'])->exists())->toBeTrue();
    if ($shared) {
        expect(array_values(array_unique(array_merge(...array_map(static fn (array $plan): array => $plan['repository_packages'] ?? [], $plans)))))->toBe(['nano', 'tree']);
    }
})->with(['then its first direct update' => ['C1a'], 'then its first direct reinstall' => ['C1b'], 'then its first direct removal' => ['C1c'],
    'beside an owned sibling sharing its source, then its first direct update' => ['C1d'],
    'before an adoption on its source, then that Tool\'s first direct reinstall' => ['C1e']]);

it('keeps the absence a Linux cask refresh refusal read with no admitted version, settled before or after the upgrade', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    if ($history === 'X6') {
        // The newer retry, still running at the upgrade, ends with the preceding decision as `decide` returns it after reading an empty
        // inventory: both version fields explicitly none.
        policyReadRun(Operation::query()->findOrFail($h['newer']['operation']), 'failed', ['Choose package action' => ['succeeded', ['ready' => false, 'mutate' => false,
            'error_code' => 'tool.candidate_version_probe_failed', 'manager_version' => 'Homebrew 5.0.0', 'installed_version' => null, 'installed_coordinate' => null,
            'candidate_json' => 'null', 'version' => '']], 'Assert package state' => ['failed']], -2);
    }
    expect($tool->refresh()->installed_version)->toBeNull();
    // The older retry's late success, read after the upgrade, sets no version over that absence.
    policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true,
        'installed_version' => null, 'installed_coordinate' => null]], 'Apply the package' => ['succeeded'], 'Check the package' => ['succeeded'],
        'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.5', 'installed_coordinate' => '1.5,2']],
        'Assert package state' => ['succeeded']], 2);
    expect($tool->refresh()->installed_version)->toBeNull();
})->with(['refused before the upgrade' => ['X5'], 'refused after the upgrade' => ['X6']]);

it('orders the version each preceding decision read, a copied admitted version as the absence it stands for', function (string $history): void {
    policySwamp();
    $histories = policyPrecedingHistories();
    $h = $histories[$history];
    $tool = Tool::query()->findOrFail($h['tool_id']);
    // X1: a reinstall refused by its constraint after reading tree absent, its refusal repeating the admitted 1.0. X2: a Linux cask reinstall's
    // refresh refused right after reading 1.3. X3: an update that read 1.3 and failed decoding its end check after the write. X4: the same
    // reinstall, after reading tree absent.
    $read = in_array($history, ['X1', 'X4'], true) ? null : '1.3';
    expect($tool->installed_version)->toBe($read);
    // The older update's late success, read after the upgrade, sets none of these back.
    policyReadRun(Operation::query()->findOrFail($h['open']['operation']), 'succeeded', ['Choose package action' => ['succeeded', ['ready' => true, 'mutate' => true,
        'installed_version' => '1.0']], 'Apply the package' => ['succeeded'], 'Check apt package' => ['succeeded'], 'Check the package' => ['succeeded'],
        'Decode package state' => ['succeeded', ['compliant' => true, 'present' => true, 'installed_version' => '1.5']], 'Assert package state' => ['succeeded']], 2);
    expect($tool->refresh()->installed_version)->toBe($read);
})->with(['a reinstall refused by its constraint' => ['X1'], 'a Linux cask reinstall refused by its refresh' => ['X2'], 'an update whose end check failed decoding' => ['X3'],
    'a reinstall whose end check failed decoding' => ['X4']]);
