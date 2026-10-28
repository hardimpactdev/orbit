<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\ToolManagerRecord;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeToolManager;

beforeEach(function (): void {
    $this->gateway = tools_api_node('tools-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = tools_api_node('tools-node', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->toolManager = new FakeToolManager(ToolManagerName::Apt);
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$this->toolManager]));
    $this->managerRecord = $this->node
        ->toolManagers()
        ->create([
            'name' => ToolManagerName::Apt,
            'status' => LifecycleStatus::Active,
        ]);
});

describe('tool reads', function (): void {
    it('rejects each strict JSON field type without leaking sentinels', function (string $json): void {
        $response = $this->call('POST', '/api/v1/tools', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($response->getContent())->not->toContain('sentinel');
    })->with([
        'node string' => ['{"node_id":"1","manager":"apt","package":"jq","version_constraint":null}'],
        'node float' => ['{"node_id":1.5,"manager":"apt","package":"jq","version_constraint":null}'],
        'node bool' => ['{"node_id":true,"manager":"apt","package":"jq","version_constraint":null}'],
        'manager null' => ['{"node_id":1,"manager":null,"package":"jq","version_constraint":null}'],
        'manager array' => ['{"node_id":1,"manager":[],"package":"jq","version_constraint":null}'],
        'manager object' => ['{"node_id":1,"manager":{},"package":"jq","version_constraint":null}'],
        'package null' => ['{"node_id":1,"manager":"apt","package":null,"version_constraint":null}'],
        'package integer' => ['{"node_id":1,"manager":"apt","package":42,"version_constraint":null}'],
        'package object' => ['{"node_id":1,"manager":"apt","package":{},"version_constraint":null}'],
        'constraint array' => ['{"node_id":1,"manager":"apt","package":"jq","version_constraint":[]}'],
        'constraint bool' => ['{"node_id":1,"manager":"apt","package":"jq","version_constraint":true}'],
    ]);

    it('lists managers for the requested node', function (): void {
        $this->node->toolManagers()->create(['name' => ToolManagerName::Vp, 'status' => LifecycleStatus::Active]);

        $response = $this
            ->getJson('/api/v1/tool-managers?node_id='.$this->node->id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'apt')
            ->assertJsonPath('data.1.name', 'vp')
            ->assertJsonStructure(['data', 'meta' => ['request_id']]);

        expect(array_keys($response->json('data.0')))->toBe([
            'id',
            'node_id',
            'name',
            'status',
            'installed_version',
            'failed_step',
            'error_code',
        ]);
    });

    it('serializes manager fields exactly and filters by node', function (): void {
        $this->managerRecord->update([
            'installed_version' => '2.0.0',
            'failed_step' => 'probe',
            'error_code' => 'tool.failed',
        ]);
        $other = tools_api_node('other-manager-node', '10.44.0.8');
        $other->toolManagers()->create(['name' => ToolManagerName::Vp, 'status' => LifecycleStatus::Failed]);
        $response = $this->getJson('/api/v1/tool-managers?node_id='.$this->node->id)->assertOk();

        expect($response->json('data'))->toBe([[
            'id' => $this->managerRecord->id,
            'node_id' => $this->node->id,
            'name' => 'apt',
            'status' => 'active',
            'installed_version' => '2.0.0',
            'failed_step' => 'probe',
            'error_code' => 'tool.failed',
        ]]);
    });

    it('lists and shows only tools belonging to the requested node', function (): void {
        $manager = $this->managerRecord;
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $manager->id,
                'package' => 'jq',
                'version_constraint' => '^1.0',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.7.1',
            ]);
        $otherNode = tools_api_node('other-tool-node', '10.44.0.7');
        $otherManager = tools_api_manager($otherNode);
        $otherNode
            ->tools()
            ->create([
                'tool_manager_id' => $otherManager->id,
                'package' => 'curl',
                'status' => ToolStatus::Installed,
                'installed_version' => '8.0.0',
            ]);

        $list = $this
            ->getJson('/api/v1/tools?node_id='.$this->node->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tool->id);
        $show = $this
            ->getJson('/api/v1/tools/'.$tool->id)
            ->assertOk()
            ->assertJsonPath('data.id', $tool->id)
            ->assertJsonPath('data.package', 'jq');

        $keys = [
            'id',
            'node_id',
            'manager',
            'package',
            'version_constraint',
            'status',
            'installed_version',
            'failed_operation',
            'error_code',
            'outcome',
        ];

        expect(array_keys($list->json('data.0')))
            ->toBe($keys)
            ->and(array_keys($show->json('data')))
            ->toBe($keys);
    });
});

describe('tool request validation and isolation', function (): void {
    it('denies all tool endpoints for an active peer without a node access edge', function (): void {
        $consumer = tools_api_node('unauthorized-consumer', '10.44.0.9');
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.7.1',
            ]);
        $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip]);
        $payload = tools_api_payload($this->node);
        $responses = [
            $this->getJson('/api/v1/tool-managers?node_id='.$this->node->id),
            $this->getJson('/api/v1/tools?node_id='.$this->node->id),
            $this->getJson('/api/v1/tools/'.$tool->id),
            $this->postJson('/api/v1/tools', $payload),
            $this->postJson('/api/v1/tools/'.$tool->id.'/update'),
            $this->deleteJson('/api/v1/tools/'.$tool->id),
        ];
        foreach ($responses as $response) {
            $response->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        }
        expect($tool->refresh()->installed_version)->toBe('1.7.1')->and($this->toolManager->calls)->toBeEmpty();
    });
    it('returns 422 for invalid strict install JSON', function (string $json, ?string $sentinel = null): void {
        $response = $this
            ->call(
                'POST',
                '/api/v1/tools',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $json,
            )
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['code', 'message', 'details']])
            ->assertHeader('X-Orbit-Request-Id');

        if ($sentinel !== null) {
            expect($response->getContent())->not->toContain($sentinel);
        }
    })->with([
        'absent body' => [''],
        'malformed' => ['{"node_id":'],
        'scalar' => ['null'],
        'unknown key' => [
            '{"node_id":1,"manager":"apt","package":"jq","version_constraint":null,"sentinel":"raw-caller-sentinel"}',
            'raw-caller-sentinel',
        ],
        'duplicate key' => ['{"node_id":1,"manager":"apt","package":"jq","package":"curl","version_constraint":null}'],
        'escaped duplicate' => [
            '{"node_id":1,"manager":"apt","package":"jq","pack\\u0061ge":"curl","version_constraint":null}',
        ],
        'wrong types' => ['{"node_id":"1","manager":["apt"],"package":42,"version_constraint":false}'],
    ]);

    it('returns 422 for absent or malformed list node identifiers', function (string $path): void {
        $this
            ->getJson($path)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    })->with([
        'tools absent' => ['/api/v1/tools'],
        'tools zero' => ['/api/v1/tools?node_id=0'],
        'tools negative' => ['/api/v1/tools?node_id=-1'],
        'tools malformed' => ['/api/v1/tools?node_id=invalid'],
        'managers absent' => ['/api/v1/tool-managers'],
        'managers zero' => ['/api/v1/tool-managers?node_id=0'],
        'managers negative' => ['/api/v1/tool-managers?node_id=-1'],
        'managers malformed' => ['/api/v1/tool-managers?node_id=invalid'],
    ]);

    it('returns 404 for syntactically valid missing identifiers', function (): void {
        $this->getJson('/api/v1/tools?node_id=999999')->assertNotFound();
        $this->getJson('/api/v1/tool-managers?node_id=999999')->assertNotFound();
        $this->getJson('/api/v1/tools/999999')->assertNotFound();
        $this->postJson('/api/v1/tools', [
            ...tools_api_payload($this->node),
            'node_id' => 999_999,
        ])->assertNotFound();
    });

    it('rejects corrupted cross-node manager ownership before mutation', function (): void {
        $other = tools_api_node('other-node', '10.44.0.4');
        $manager = tools_api_manager($other);
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $manager->id,
                'package' => 'jq',
                'status' => ToolStatus::Installed,
            ]);

        $this->getJson('/api/v1/tools/'.$tool->id)->assertOk();
        assert_tools_api_error(
            $this->postJson('/api/v1/tools/'.$tool->id.'/update'),
            409,
            'tool.state_invalid',
            'manager_failed',
            'update',
            $tool->id,
        );
        assert_tools_api_error(
            $this->deleteJson('/api/v1/tools/'.$tool->id),
            409,
            'tool.state_invalid',
            'manager_failed',
            'remove',
            $tool->id,
        );
    });
});

function tools_api_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', times: 43),
    ]);
}

function tools_api_manager(Node $node): ToolManagerRecord
{
    return $node->toolManagers()->create(['name' => ToolManagerName::Apt, 'status' => LifecycleStatus::Active]);
}

/** @return array{node_id:int, manager:string, package:string, version_constraint:string} */
function tools_api_payload(Node $node): array
{
    return ['node_id' => $node->id, 'manager' => 'apt', 'package' => 'jq', 'version_constraint' => '^1.0'];
}

function assert_tools_api_error(
    TestResponse $response,
    int $status,
    string $code,
    string $outcome,
    string $step,
    ?int $toolId = null,
): void {
    $details = ['step', 'outcome'];

    $response
        ->assertStatus($status)
        ->assertHeader('X-Orbit-Request-Id')
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details.step', $step)
        ->assertJsonPath('error.details.outcome', $outcome);

    if ($toolId !== null) {
        $details[] = 'id';
        $response->assertJsonPath('error.details.id', $toolId);
    }

    expect(array_keys($response->json()))
        ->toBe(['error'])
        ->and(array_keys($response->json('error')))
        ->toBe(['code', 'message', 'details'])
        ->and(array_keys($response->json('error.details')))
        ->toBe($details);
}
