<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroupEgress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

function egress_http_node(string $name, string $ip, array $settings = []): Node
{
    return Node::withoutEvents(function () use ($name, $ip, $settings): Node {
        $node = Node::query()->create([
            'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
            'settings' => $settings ?: null,
        ]);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:fingerprint', 'agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
}

function egress_http_group(): Task
{
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
}

function egress_http_read(Node $caller, Task $group): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson("/api/v1/tasks/{$group->id}/egress");
}

function egress_http_report(Node $node, array $data, string $subject = 'shark-tvm-1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
        ->postJson('/api/v1/agent/facts', ['kind' => 'egress', 'subject' => $subject, 'observed_at' => '2026-11-07T19:00:10Z', 'data' => $data]);
}

describe('GET /api/v1/tasks/{task}/egress', function (): void {
    beforeEach(function (): void {
        $this->gateway = egress_http_node('gateway', '10.44.0.2');
        $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->group = egress_http_group();
        TaskGroupEgress::query()->create(['task_id' => $this->group->id, 'vm_name' => 'shark-tvm-1', 'destination' => 'registry.npmjs.org', 'kind' => 'name', 'port' => 443,
            'protocol' => 'tcp', 'addresses' => ['104.16.1.34'], 'sources' => ['conn', 'dns'], 'flags' => [], 'first_seen_at' => '2026-11-07 19:00:05']);
    });

    it('lists the rows of the group for a peer with access to the Gateway', function (): void {
        app(TaskExtensionState::class)->enable();

        $response = egress_http_read($this->gateway, $this->group)->assertOk();

        expect($response->json('data.0'))->toMatchArray(['destination' => 'registry.npmjs.org', 'port' => 443, 'protocol' => 'tcp', 'vm' => 'shark-tvm-1'])
            ->and($response->json('meta.request_id'))->toBeString();
    });

    it('refuses a peer with no access to the Gateway', function (): void {
        app(TaskExtensionState::class)->enable();
        $other = egress_http_node('other', '10.44.0.9');

        egress_http_read($other, $this->group)->assertForbidden();
    });

    it('answers 409 while the tasks extension is off', function (): void {
        egress_http_read($this->gateway, $this->group)->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    });
});

describe('POST /api/v1/agent/facts for the egress kind', function (): void {
    $fact = [
        'network' => 'orbittask0', 'mac' => '10:66:6a:f0:65:d4', 'overflow' => 0,
        'rows' => [['destination' => 'registry.npmjs.org', 'kind' => 'name', 'port' => 443, 'protocol' => 'tcp', 'addresses' => ['104.16.1.34'],
            'sources' => ['conn'], 'flags' => [], 'first_seen' => '2026-11-07T19:00:05Z']],
    ];

    it('accepts the report of a task VM host for its own bridge', function () use ($fact): void {
        $host = egress_http_node('shark', '10.44.0.11', ['task_vms' => ['cidr' => '10.251.78.0/24', 'task_places' => 4, 'ci_places' => 2]]);

        egress_http_report($host, $fact)->assertNoContent();
    });

    it('refuses a report from a Node that is no task VM host', function () use ($fact): void {
        egress_http_report(egress_http_node('apps', '10.44.0.5'), $fact)->assertStatus(422)->assertJsonPath('error.code', 'agent.fact_not_expected');
    });

    it('refuses a report for another bridge than the host\'s', function () use ($fact): void {
        $host = egress_http_node('shark', '10.44.0.11', ['task_vms' => ['cidr' => '10.251.78.0/24', 'task_places' => 4, 'ci_places' => 2]]);

        egress_http_report($host, ['network' => 'orbittaskb'] + $fact)->assertStatus(422)->assertJsonPath('error.code', 'agent.fact_not_expected');
    });

    it('accepts a report for the container bridge only from a host with task containers', function () use ($fact): void {
        $vms = ['cidr' => '10.251.78.0/24', 'task_places' => 4, 'ci_places' => 2];
        $host = egress_http_node('shark', '10.44.0.11', ['task_vms' => $vms + ['containers' => ['cidr' => '10.251.79.0/24', 'task_places' => 1, 'ci_places' => 1]]]);
        $plain = egress_http_node('beast', '10.44.0.9', ['task_vms' => ['cidr' => '10.251.80.0/24'] + $vms]);

        egress_http_report($host, ['network' => 'orbittaskc0'] + $fact)->assertNoContent();
        egress_http_report($host, $fact)->assertNoContent();
        egress_http_report($plain, ['network' => 'orbittaskc0'] + $fact)->assertStatus(422)->assertJsonPath('error.code', 'agent.fact_not_expected');
    });
});
