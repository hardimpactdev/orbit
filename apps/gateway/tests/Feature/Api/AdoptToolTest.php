<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeToolManager;

beforeEach(function (): void {
    $this->gateway = adopt_api_node('adopt-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = adopt_api_node('adopt-node', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    $this->toolManager = new FakeToolManager(ToolManagerName::Apt);
    $this->toolManager->adoption = new ToolAdoptionFact('1.2.3', null);
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$this->toolManager]));
});

describe('tool adoption api', function (): void {

    it('returns state invalid for a tool left installing', function (): void {
        $record = adopt_api_manager($this->node);
        $tool = $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'status' => ToolStatus::Installing,
            'installed_version' => null,
        ]);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node));
        assert_adopt_error($response, 409, 'tool.state_invalid', $tool->id);
        expect($tool->refresh()->status)->toBe(ToolStatus::Installing);
        record_adopt_fixture($response, 'state-invalid');
    });

    it('conflicts on a different constraint', function (): void {
        $record = adopt_api_manager($this->node);
        $tool = $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'version_constraint' => '^1.0',
            'status' => ToolStatus::Installed,
            'installed_version' => '1.2.3',
        ]);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^2.0'));
        assert_adopt_error($response, 409, 'tool.constraint_conflict', $tool->id);
        record_adopt_fixture($response, 'constraint-conflict');
    });

    it('rejects an unsupported manager and an invalid package or constraint before a tool exists', function (
        string $field,
        string $value,
        string $code,
        string $fixture,
    ): void {
        $payload = adopt_api_payload($this->node);
        $payload[$field] = $value;

        if ($field === 'package') {
            $this->toolManager->validPackage = false;
        }

        $response = $this->postJson('/api/v1/tools/adopt', $payload);
        assert_adopt_error($response, 422, $code);
        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, $fixture);
    })->with([
        'manager' => ['manager', 'npm', 'tool.manager_unsupported', 'manager-unsupported'],
        'package' => ['package', '--option', 'tool.package_invalid', 'package-invalid'],
        'constraint' => ['version_constraint', 'not a constraint', 'tool.constraint_invalid', 'constraint-invalid'],
    ]);

    it('rejects an unknown field before lookup', function (): void {
        $payload = adopt_api_payload($this->node);
        $payload['sudo'] = true;
        $response = $this->postJson('/api/v1/tools/adopt', $payload)->assertUnprocessable();
        expect($response->json('error.code'))->toBe('validation.failed')
            ->and(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, 'validation-failed');
    });

    it('rejects an inactive or unmanaged node', function (string $field, string $code, string $fixture): void {
        $this->node->update([$field => $field === 'status' ? LifecycleStatus::Failed : null]);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node));
        assert_adopt_error($response, 409, $code);
        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, $fixture);
    })->with([
        'inactive' => ['status', 'tool.node_inactive', 'node-inactive'],
        'unmanaged' => ['ssh_host_fingerprint', 'tool.node_unmanaged', 'node-unmanaged'],
    ]);

    it('denies a peer without access', function (): void {
        $consumer = adopt_api_node('adopt-consumer', '10.44.0.9');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
            ->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node))
            ->assertForbidden();

        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, 'access-required');
    });

});

/** @return array{node_id: int, manager: string, package: string, version_constraint: ?string} */
function adopt_api_node(string $name, string $address): Node
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

function adopt_api_payload(Node $node, ?string $constraint = null): array
{
    return [
        'node_id' => $node->id,
        'manager' => 'apt',
        'package' => 'jq',
        'version_constraint' => $constraint,
    ];
}

function adopt_api_manager(Node $node): ToolManagerRecord
{
    return $node->toolManagers()->create([
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
}

function record_adopt_fixture(TestResponse $response, string $name): void
{
    record_fixture(
        $response,
        'tools/tool-adopt/'.$name,
        'Orbit\\Sdk\\Requests\\Tools\\AdoptToolRequest',
        'POST /api/v1/tools/adopt',
    );
}

function assert_adopt_error(
    TestResponse $response,
    int $status,
    string $code,
    ?int $toolId = null,
    ?string $adoptionBlock = null,
): void {
    $details = ['step', 'outcome'];

    $response
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details.step', 'adopt')
        ->assertJsonPath('error.details.outcome', $code === 'tool.constraint_invalid' ? 'constraint_invalid' : 'manager_failed');

    if ($toolId !== null) {
        $details[] = 'id';
        $response->assertJsonPath('error.details.id', $toolId);
    }

    if ($adoptionBlock !== null) {
        $details[] = 'adoption_block';
        $response->assertJsonPath('error.details.adoption_block', $adoptionBlock);
    }

    expect(array_keys($response->json('error.details')))->toBe($details);
}
