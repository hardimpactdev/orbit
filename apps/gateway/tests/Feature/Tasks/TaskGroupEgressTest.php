<?php

declare(strict_types=1);

use App\Actions\NodeFacts\AgentWatchListAction;
use App\Actions\NodeFacts\RecordTaskGroupEgressAction;
use App\Actions\Tasks\ShowTaskGroupEgressAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Http\Controllers\Api\TaskEgressController;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroupEgress;
use App\Models\TaskVm;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;

uses(LazilyRefreshDatabase::class);

/** One row of an egress fact, as the agent reports it. */
function egress_row(array $row = []): array
{
    return $row + [
        'destination' => 'registry.npmjs.org', 'kind' => 'name', 'port' => 443, 'protocol' => 'tcp', 'addresses' => ['104.16.1.34'],
        'sources' => ['conn', 'dns'], 'flags' => [], 'first_seen' => '2026-11-07T19:00:05Z',
    ];
}

/** An egress fact of one VM. */
function egress_fact(array ...$rows): array
{
    return ['network' => 'orbittask0', 'mac' => '10:66:6a:f0:65:d4', 'rows' => $rows === [] ? [egress_row()] : $rows, 'overflow' => 0];
}

/**
 * A task VM host, and a VM `shark-tvm-1` on it that a task group holds since 19:00.
 *
 * @return array{Node, Task, TaskVm}
 */
function egress_world(TaskVmState $state = TaskVmState::Ready, bool $grouped = true): array
{
    $node = static fn (string $name, string $address, array $settings = []): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'fixture-public-key', 'settings' => $settings ?: null,
    ]));
    $host = $node('shark', '10.44.0.11', ['task_vms' => ['cidr' => '10.251.78.0/24', 'max_vms' => 4]]);
    $guest = $node('shark-tvm-1', '10.44.0.133');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $grouped ? $group->id : null, 'provider' => TaskVmProviderKind::Incus,
        'name' => 'shark-tvm-1', 'slot_number' => 1, 'slot_project_id' => $project->id, 'state' => $state, 'wireguard_ip' => '10.44.0.133', 'size' => TaskVmSize::Large,
        'claimed_at' => CarbonImmutable::parse('2026-11-07T19:00:00Z')]);

    return [$host, $group, $vm];
}

it('accepts the egress fact the agent reports', function (): void {
    $fact = egress_fact(egress_row(), egress_row(['destination' => '140.82.121.4', 'kind' => 'address', 'port' => 22, 'addresses' => ['140.82.121.4'], 'sources' => ['conn'], 'flags' => ['dot']]),
        egress_row(['destination' => 'example.com', 'port' => null, 'protocol' => null, 'sources' => ['dns']]));

    expect(Validator::make($fact, NodeFactKind::Egress->rules())->passes())->toBeTrue()
        ->and(Validator::make(['rows' => []] + egress_fact(), NodeFactKind::Egress->rules())->passes())->toBeTrue()
        ->and(NodeFactKind::Egress->hasSubject())->toBeTrue()
        ->and(Validator::make(['subject' => 'shark-tvm-1'], ['subject' => NodeFactKind::Egress->subjectRules()])->passes())->toBeTrue();
});

it('refuses an egress report with an unsafe row', function (array $fact): void {
    expect(Validator::make($fact, NodeFactKind::Egress->rules())->fails())->toBeTrue();
})->with([
    'upper case destination' => [egress_fact(egress_row(['destination' => 'Registry.NPM']))],
    'destination with a slash' => [egress_fact(egress_row(['destination' => 'a.com/path']))],
    'unknown kind' => [egress_fact(egress_row(['kind' => 'url']))],
    'port out of range' => [egress_fact(egress_row(['port' => 70000]))],
    'unknown protocol' => [egress_fact(egress_row(['protocol' => 'icmp']))],
    'IPv6 address' => [egress_fact(egress_row(['addresses' => ['2001:db8::1']]))],
    'too many addresses' => [egress_fact(egress_row(['addresses' => array_fill(0, 9, '1.1.1.1')]))],
    'no source' => [egress_fact(egress_row(['sources' => []]))],
    'unknown flag' => [egress_fact(egress_row(['flags' => ['tor']]))],
    'date without a zone' => [egress_fact(egress_row(['first_seen' => '2026-11-07 19:00:05']))],
    'port without protocol' => [egress_fact(egress_row(['protocol' => null]))],
    'protocol without port' => [egress_fact(egress_row(['port' => null]))],
    'port as a string' => [egress_fact(egress_row(['port' => '443']))],
    'bad bridge name' => [['network' => 'eth0'] + egress_fact()],
    'bad MAC' => [['mac' => 'zz'] + egress_fact()],
]);

it('caps a report at 1,500 rows', function (): void {
    expect(NodeFactKind::Egress->rules()['rows'])->toContain('max:1500');
});

it('adds the rows of a VM to the group that holds it, once', function (): void {
    [$host, $group] = egress_world();
    $record = app(RecordTaskGroupEgressAction::class);

    expect($record->execute($host, 'shark-tvm-1', egress_fact(egress_row(), egress_row(['destination' => 'example.com', 'port' => null, 'protocol' => null, 'addresses' => [], 'sources' => ['dns']]))))->toBe(2)
        ->and($record->execute($host, 'shark-tvm-1', egress_fact(egress_row())))->toBe(0);

    $rows = TaskGroupEgress::query()->where('task_id', $group->id)->orderBy('destination')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->destination)->toBe('example.com')->and($rows[0]->port)->toBe(0)->and($rows[0]->protocol)->toBe('')
        ->and($rows[1]->destination)->toBe('registry.npmjs.org')->and($rows[1]->port)->toBe(443)->and($rows[1]->vm_name)->toBe('shark-tvm-1');
});

it('merges addresses, sources and flags of a later report and never removes a row', function (): void {
    [$host, $group] = egress_world();
    $record = app(RecordTaskGroupEgressAction::class);
    $record->execute($host, 'shark-tvm-1', egress_fact(egress_row(['sources' => ['conn']])));

    expect($record->execute($host, 'shark-tvm-1', egress_fact(egress_row(['addresses' => ['104.16.2.34'], 'sources' => ['dns'], 'flags' => ['doh']]))))->toBe(1)
        // A report without the row leaves it in place.
        ->and($record->execute($host, 'shark-tvm-1', egress_fact(egress_row(['destination' => 'github.com']))))->toBe(1);

    $row = TaskGroupEgress::query()->where('task_id', $group->id)->where('destination', 'registry.npmjs.org')->sole();
    expect($row->addresses)->toBe(['104.16.1.34', '104.16.2.34'])->and($row->sources)->toBe(['conn', 'dns'])->and($row->flags)->toBe(['doh'])
        ->and(TaskGroupEgress::query()->where('task_id', $group->id)->count())->toBe(2);
});

it('adds nothing for a VM that no group holds, a destroyed VM or a row seen before the claim', function (): void {
    [$host] = egress_world(grouped: false);
    $record = app(RecordTaskGroupEgressAction::class);

    expect($record->execute($host, 'shark-tvm-1', egress_fact()))->toBe(0)
        ->and($record->execute($host, 'unknown-vm', egress_fact()))->toBe(0);

    TaskVm::query()->where('name', 'shark-tvm-1')->update(['group_id' => Task::query()->value('id'), 'state' => TaskVmState::Destroyed]);
    expect($record->execute($host, 'shark-tvm-1', egress_fact()))->toBe(0);

    TaskVm::query()->where('name', 'shark-tvm-1')->update(['state' => TaskVmState::Ready]);
    expect($record->execute($host, 'shark-tvm-1', egress_fact(egress_row(['first_seen' => '2026-11-07T18:59:59Z']))))->toBe(0)
        ->and(TaskGroupEgress::query()->count())->toBe(0);
});

it('keeps the rows of a group after its VM is destroyed and gives a reused VM name to the new group only', function (): void {
    [$host, $first, $vm] = egress_world();
    $record = app(RecordTaskGroupEgressAction::class);
    $record->execute($host, 'shark-tvm-1', egress_fact());

    $second = Task::query()->create(['project_id' => $first->project_id, 'title' => 'Second', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $vm->update(['state' => TaskVmState::Destroyed]);
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'shark-tvm-1b', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.134',
        'public_ssh_host' => '10.44.0.134', 'user' => 'orbit']));
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $second->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'shark-tvm-1',
        'slot_number' => 1, 'slot_project_id' => $first->project_id, 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.134', 'size' => TaskVmSize::Large,
        'claimed_at' => CarbonImmutable::parse('2026-11-07T20:00:00Z')]);
    $record->execute($host, 'shark-tvm-1', egress_fact(egress_row(['destination' => 'github.com', 'first_seen' => '2026-11-07T20:01:00Z'])));

    expect(TaskGroupEgress::query()->where('task_id', $first->id)->pluck('destination')->all())->toBe(['registry.npmjs.org'])
        ->and(TaskGroupEgress::query()->where('task_id', $second->id)->pluck('destination')->all())->toBe(['github.com']);
});

it('stops a group at the row limit', function (): void {
    [$host, $group] = egress_world();
    $now = now()->toDateTimeString();
    TaskGroupEgress::query()->insert(array_map(static fn (int $i): array => [
        'task_id' => $group->id, 'vm_name' => 'shark-tvm-1', 'destination' => "h{$i}.example", 'kind' => 'name', 'port' => 443, 'protocol' => 'tcp',
        'addresses' => '[]', 'sources' => '["dns"]', 'flags' => '[]', 'first_seen_at' => $now,
    ], range(1, RecordTaskGroupEgressAction::MaxRows)));

    expect(app(RecordTaskGroupEgressAction::class)->execute($host, 'shark-tvm-1', egress_fact()))->toBe(0)
        ->and(TaskGroupEgress::query()->where('task_id', $group->id)->count())->toBe(RecordTaskGroupEgressAction::MaxRows);
});

it('lists the rows of a group through the API controller, with null for a name nothing connected to', function (): void {
    [$host, $group] = egress_world();
    app(TaskExtensionState::class)->enable();
    app(RecordTaskGroupEgressAction::class)->execute($host, 'shark-tvm-1', egress_fact(egress_row(), egress_row(['destination' => 'example.com', 'port' => null, 'protocol' => null, 'sources' => ['dns']])));
    $request = Request::create("/api/v1/tasks/{$group->id}/egress");
    $request->attributes->set('orbit.request_id', 'request-1');

    $body = json_decode((string) app(TaskEgressController::class)->show($request, $group, app(ShowTaskGroupEgressAction::class))->getContent(), true);

    expect($body['meta']['request_id'])->toBe('request-1')
        ->and(array_column($body['data'], 'destination'))->toBe(['example.com', 'registry.npmjs.org'])
        ->and($body['data'][0])->toMatchArray(['port' => null, 'protocol' => null, 'sources' => ['dns'], 'vm' => 'shark-tvm-1'])
        ->and($body['data'][1])->toMatchArray(['kind' => 'name', 'port' => 443, 'protocol' => 'tcp', 'addresses' => ['104.16.1.34'], 'first_seen_at' => '2026-11-07T19:00:05+00:00']);
});

it('refuses the egress list while the tasks extension is off', function (): void {
    [, $group] = egress_world();

    expect(fn () => app(ShowTaskGroupEgressAction::class)->execute($group))->toThrow(ResourceOperationException::class);
});

it('names the bridge in the watch list of a task VM host only', function (): void {
    [$host] = egress_world();
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'apps', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.5',
        'public_ssh_host' => '10.44.0.5', 'user' => 'orbit']));

    expect(app(AgentWatchListAction::class)->execute($host)['egress'])->toBe(['network' => 'orbittask0'])
        ->and(app(AgentWatchListAction::class)->execute($other)['egress'])->toBeNull();

    $host->update(['settings' => ['task_vms' => ['cidr' => '10.251.78.0/24', 'max_vms' => 4, 'network' => 'orbittaskb']]]);
    expect(app(AgentWatchListAction::class)->execute($host->refresh())['egress'])->toBe(['network' => 'orbittaskb']);
});

it('writes nothing for a report that adds nothing, and merges two rows with one key', function (): void {
    [$host, $group] = egress_world();
    $record = app(RecordTaskGroupEgressAction::class);
    $twice = egress_fact(egress_row(['addresses' => ['9.9.9.9', '1.1.1.1'], 'sources' => ['dns', 'conn']]), egress_row(['addresses' => ['5.5.5.5'], 'flags' => ['doh']]));

    expect($record->execute($host, 'shark-tvm-1', $twice))->toBe(1)
        ->and(TaskGroupEgress::query()->where('task_id', $group->id)->sole()->addresses)->toBe(['1.1.1.1', '5.5.5.5', '9.9.9.9']);

    $queries = 0;
    DB::listen(static function ($query) use (&$queries): void {
        if (! str_starts_with(strtolower($query->sql), 'select')) {
            $queries++;
        }
    });
    // SQLite starts a transaction with PDO::exec, which fires this event and not a query event.
    Event::listen(TransactionBeginning::class, static function () use (&$queries): void {
        $queries++;
    });

    expect($record->execute($host, 'shark-tvm-1', $twice))->toBe(0)->and($queries)->toBe(0);
});

it('lists a name without a port only until the VM connected to it', function (): void {
    [$host, $group] = egress_world();
    app(TaskExtensionState::class)->enable();
    $record = app(RecordTaskGroupEgressAction::class);
    $dnsOnly = egress_row(['port' => null, 'protocol' => null, 'sources' => ['dns']]);
    $record->execute($host, 'shark-tvm-1', egress_fact($dnsOnly, egress_row(['destination' => 'github.com', 'port' => null, 'protocol' => null, 'sources' => ['dns']])));

    expect(app(ShowTaskGroupEgressAction::class)->execute($group)->pluck('destination')->all())->toBe(['github.com', 'registry.npmjs.org']);

    $record->execute($host, 'shark-tvm-1', egress_fact(egress_row()));

    expect(app(ShowTaskGroupEgressAction::class)->execute($group)->map(fn (TaskGroupEgress $row): string => $row->destination.':'.$row->port)->all())
        ->toBe(['github.com:0', 'registry.npmjs.org:443'])
        ->and(TaskGroupEgress::query()->where('task_id', $group->id)->count())->toBe(3);
});

it('takes no write lock for a report of a group at its row limit', function (): void {
    [$host, $group] = egress_world();
    $now = now()->toDateTimeString();
    TaskGroupEgress::query()->insert(array_map(static fn (int $i): array => [
        'task_id' => $group->id, 'vm_name' => 'shark-tvm-1', 'destination' => "h{$i}.example", 'kind' => 'name', 'port' => 443, 'protocol' => 'tcp',
        'addresses' => '[]', 'sources' => '["dns"]', 'flags' => '[]', 'first_seen_at' => $now,
    ], range(1, RecordTaskGroupEgressAction::MaxRows)));
    $transactions = 0;
    Event::listen(TransactionBeginning::class, static function () use (&$transactions): void {
        $transactions++;
    });

    expect(app(RecordTaskGroupEgressAction::class)->execute($host, 'shark-tvm-1', egress_fact()))->toBe(0)->and($transactions)->toBe(0);
});
