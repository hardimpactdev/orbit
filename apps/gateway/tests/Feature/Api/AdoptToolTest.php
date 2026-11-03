<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeToolManager;

beforeEach(function (): void {
    $this->gateway = adopt_api_node('adopt-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = adopt_api_node('adopt-node', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    $this->toolManager = new FakeToolManager(ToolManagerName::Apt);
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

describe('adoption from the agent report', function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([new FakeToolManager(ToolManagerName::Apt),
            new FakeToolManager(ToolManagerName::Composer), new FakeToolManager(ToolManagerName::Brew)]));
        Carbon::setTestNow('2026-11-03 05:00:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
        seed_agent_view($this->node->id, []);
        adopt_api_fact($this->node, 'apt', ['packages' => [['name' => 'jq', 'version' => '1.7.1']]]);
    });
    afterEach(fn () => Carbon::setTestNow());

    it('records the reported package as an installed Tool at once, with no task', function (): void {
        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^1.7'))->assertCreated()
            ->assertJsonPath('data.package', 'jq')->assertJsonPath('data.status', 'installed')
            ->assertJsonPath('data.installed_version', '1.7.1')->assertJsonPath('data.outcome', 'applied')
            ->assertJsonPath('data.agent', ['state' => 'online', 'since' => null]);
        expect(Operation::query()->count())->toBe(0)->and(Tool::query()->sole()->status)->toBe(ToolStatus::Installed)
            ->and($this->node->toolManagers()->sole()->status)->toBe(LifecycleStatus::Active);
        record_adopt_fixture($response, 'adopted');

        $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^1.7'))->assertCreated()->assertJsonPath('data.outcome', 'unchanged');
        expect(Tool::query()->count())->toBe(1);
    });

    it('answers from the last report while the agent is silent, and says so', function (): void {
        Carbon::setTestNow('2026-11-03 05:05:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);

        $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node))->assertCreated()
            ->assertJsonPath('data.agent', ['state' => 'offline', 'since' => '2026-11-03T05:00:00Z']);
    });

    it('refuses without recording when the report does not show an adoptable package', function (Closure $report, array $payload, string $code, ?string $block): void {
        $report->call($this);

        $response = $this->postJson('/api/v1/tools/adopt', [...adopt_api_payload($this->node), ...$payload])->assertConflict()
            ->assertJsonPath('error.code', $code)->assertJsonPath('error.details.agent.state', 'online')
            ->assertJsonPath('error.details.adoption_block', $block);
        expect(Tool::query()->count())->toBe(0)->and(ToolManagerRecord::query()->count())->toBe(0);
        if ($code === 'tool.not_observed') {
            record_adopt_fixture($response, 'not-observed');
        }
    })->with([
        'not observed yet' => [fn () => NodeFact::query()->delete(), [], 'tool.not_observed', null],
        'not installed' => [fn () => null, ['package' => 'ripgrep'], 'tool.package_absent', null],
        'outside the constraint' => [fn () => null, ['version_constraint' => '^2.0'], 'tool.installed_version_constraint_violated', null],
        'unreadable version' => [fn () => adopt_api_fact(test()->node, 'apt', ['packages' => [['name' => 'jq', 'version' => '1.7.1-1ubuntu1']]]),
            ['version_constraint' => '^1.7'], 'tool.installed_version_unparseable', null],
        'a Composer dependency' => [fn () => adopt_api_fact(test()->node, 'composer', ['roots' => ['laravel/installer'],
            'packages' => [['name' => 'symfony/console', 'version' => '7.3.0']]]), ['manager' => 'composer', 'package' => 'symfony/console'], 'tool.adoption_unsupported', 'dependency'],
        'a formula from another tap' => [fn () => adopt_api_fact(test()->node, 'brew', ['formulae' => [['name' => 'jq', 'versions' => ['1.8.1'],
            'tap' => 'example/tap', 'on_request' => true, 'dependencies' => []]], 'casks' => []]), ['manager' => 'brew'], 'tool.adoption_unsupported', 'unsupported_source'],
        'several formula versions' => [fn () => adopt_api_fact(test()->node, 'brew', ['formulae' => [['name' => 'jq', 'versions' => ['1.7.1', '1.8.1'],
            'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => []]], 'casks' => []]), ['manager' => 'brew'], 'tool.adoption_unsupported', 'version_unreadable'],
    ]);

    it('checks the apt source and signing key Orbit writes before recording the repository', function (bool $matches): void {
        $key = (string) file_get_contents(base_path('tests/Fixtures/Tools/apt-signing-key.gpg'));
        $repository = ['name' => 'vendor', 'uris' => ['https://packages.example.test/apt'], 'suites' => ['stable'], 'components' => ['main'],
            'signing_key' => ['url' => 'https://packages.example.test/key.gpg', 'fingerprint' => $matches ? 'B9450981404CF6FEC99E5181D8861E7192E7461F' : str_repeat('A', 40)]];
        adopt_api_fact($this->node, 'apt', ['packages' => [['name' => 'jq', 'version' => '1.7.1']],
            'sources' => [['path' => '/etc/apt/sources.list.d/orbit-vendor.sources', 'content' => base64_encode("Types: deb\nURIs: https://packages.example.test/apt\nSuites: stable\nComponents: main\nArchitectures: amd64\nSigned-By: /etc/apt/keyrings/orbit-vendor.gpg\n")]],
            'keys' => [['path' => '/etc/apt/keyrings/orbit-vendor.gpg', 'content' => base64_encode($key)]]]);

        $response = $this->postJson('/api/v1/tools/adopt', [...adopt_api_payload($this->node), 'repository' => $repository]);

        if ($matches) {
            $response->assertCreated()->assertJsonPath('data.repository.name', 'vendor');
            expect(AptRepository::query()->sole()->definition)->toBe($repository)->and(Tool::query()->sole()->apt_repository_id)->toBe(AptRepository::query()->sole()->id);
        } else {
            $response->assertConflict()->assertJsonPath('error.code', 'tool.repository_adoption_mismatch');
            expect(AptRepository::query()->count())->toBe(0)->and(Tool::query()->count())->toBe(0);
        }
    })->with(['matching' => [true], 'another key' => [false]]);
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

/** @param array<string, mixed> $data */
function adopt_api_fact(Node $node, string $manager, array $data): void
{
    NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => 'packages', 'subject' => $manager], [
        'digest' => bin2hex(random_bytes(32)), 'observed_at' => now(), 'received_at' => now(), 'data' => ['scope' => '/', ...$data]]);
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

    // The agent's liveness comes last; a Node without an observing agent has none.
    if ($response->json('error.details.agent') !== null) {
        $details[] = 'agent';
    }

    expect(array_keys($response->json('error.details')))->toBe($details);
}
