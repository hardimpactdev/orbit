<?php

declare(strict_types=1);

use App\Actions\NodeFacts\AgentWatchListAction;
use App\Actions\Tasks\ShowTaskGroupEgressAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Controllers\Api\TaskEgressController;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroupEgress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
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

/** One row that the Gateway recorded for a group before it refused every egress fact. */
function stored_egress_row(Task $group, array $row): TaskGroupEgress
{
    return TaskGroupEgress::query()->forceCreate($row + ['task_id' => $group->id, 'vm_name' => 'shark-tvm-1', 'kind' => 'name', 'port' => 0, 'protocol' => '',
        'addresses' => [], 'sources' => ['dns'], 'flags' => [], 'first_seen_at' => '2026-11-07T19:00:05Z']);
}

/**
 * A former task VM host and a running task group.
 *
 * @return array{Node, Task}
 */
function egress_world(): array
{
    $node = static fn (string $name, string $address, array $settings = []): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'fixture-public-key', 'settings' => $settings ?: null,
    ]));
    $host = $node('shark', '10.44.0.11');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);

    return [$host, $group];
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

it('lists the rows of a group through the API controller, with null for a name nothing connected to', function (): void {
    [, $group] = egress_world();
    app(TaskExtensionState::class)->enable();
    stored_egress_row($group, ['destination' => 'registry.npmjs.org', 'port' => 443, 'protocol' => 'tcp', 'addresses' => ['104.16.1.34']]);
    stored_egress_row($group, ['destination' => 'example.com']);
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

it('sends no egress bridge in the watch list, also to a Node whose settings still hold a task_vms key', function (): void {
    [$host] = egress_world();
    $host->update(['settings' => ['task_vms' => ['cidr' => '10.251.78.0/24', 'task_places' => 4, 'ci_places' => 2, 'network' => 'orbittaskb',
        'containers' => ['cidr' => '10.251.79.0/24', 'task_places' => 1]]]]);

    expect(app(AgentWatchListAction::class)->execute($host->refresh()))->not->toHaveKey('egress');
});

it('lists a name without a port only until the VM connected to it', function (): void {
    [, $group] = egress_world();
    app(TaskExtensionState::class)->enable();
    stored_egress_row($group, ['destination' => 'registry.npmjs.org']);
    stored_egress_row($group, ['destination' => 'github.com']);

    expect(app(ShowTaskGroupEgressAction::class)->execute($group)->pluck('destination')->all())->toBe(['github.com', 'registry.npmjs.org']);

    stored_egress_row($group, ['destination' => 'registry.npmjs.org', 'port' => 443, 'protocol' => 'tcp', 'sources' => ['conn']]);

    expect(app(ShowTaskGroupEgressAction::class)->execute($group)->map(fn (TaskGroupEgress $row): string => $row->destination.':'.$row->port)->all())
        ->toBe(['github.com:0', 'registry.npmjs.org:443'])
        ->and(TaskGroupEgress::query()->where('task_id', $group->id)->count())->toBe(3);
});
