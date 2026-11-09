<?php

declare(strict_types=1);

use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

function role_list_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

describe('recorded Node role reads', function (): void {
    beforeEach(function (): void {
        $this->caller = role_list_node('operator', '10.44.0.9');
        $this->node = role_list_node('target', '10.44.0.2');
        $this->caller->accessibleNodes()->attach($this->node);
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        $this->mock(AgentCommandRunner::class)->shouldNotReceive('run', 'install');
    });

    it('projects every assignment identically through role list, Node list and Node show', function (): void {
        $expected = [];
        foreach ([
            ['websocket', 'removing', null, null],
            ['database', 'failed', 'converge:packages', 'packages.failed'],
            ['app-prod', 'provisioning', null, null],
            ['app-dev', 'active', 'converge:check', 'check.failed'],
        ] as [$role, $status, $step, $code]) {
            $assignment = $this->node->roles()->create([
                'role' => $role, 'status' => $status, 'failed_step' => $step, 'error_code' => $code,
            ]);
            array_unshift($expected, ['id' => $assignment->id, 'role' => $role, 'status' => $status,
                'failed_step' => $step, 'error_code' => $code]);
        }
        $before = [Node::all()->toArray(), NodeRole::all()->toArray(), Operation::all()->toArray()];

        for ($read = 0; $read < 2; $read++) {
            $this->getJson('/api/v1/nodes/'.$this->node->id.'/roles')->assertOk()
                ->assertJsonPath('data', $expected)->assertJsonStructure(['meta' => ['request_id']]);
            $this->getJson('/api/v1/nodes/'.$this->node->id)->assertOk()
                ->assertJsonPath('data.assignments', $expected)->assertJsonMissingPath('data.role_assignments');
            $this->getJson('/api/v1/nodes')->assertOk()
                ->assertJsonPath('data.0.assignments', $expected)->assertJsonMissingPath('data.0.role_assignments');
        }

        expect([Node::all()->toArray(), NodeRole::all()->toArray(), Operation::all()->toArray()])->toBe($before);
    });

    it('returns an empty assignment list successfully', function (): void {
        $this->getJson('/api/v1/nodes/'.$this->node->id.'/roles')->assertOk()->assertJsonPath('data', []);
        expect(Operation::query()->count())->toBe(0);
    });

    it('reads unchanged records with stale or missing agent facts and an unreachable Node', function (bool $hasFacts): void {
        $assignment = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        if ($hasFacts) {
            NodeFact::query()->create([
                'node_id' => $this->node->id, 'kind' => NodeFactKind::Accounts,
                'digest' => str_repeat('a', 64), 'data' => ['accounts' => []],
                'observed_at' => now()->subDays(2), 'received_at' => now()->subDays(2),
            ]);
        }
        $before = [Node::all()->toArray(), NodeRole::all()->toArray(), NodeFact::all()->toArray()];
        $started = microtime(true);

        $this->getJson('/api/v1/nodes/'.$this->node->id.'/roles')->assertOk()->assertJsonPath('data', [[
            'id' => $assignment->id, 'role' => 'app-dev', 'status' => 'active', 'failed_step' => null, 'error_code' => null,
        ]]);

        expect(microtime(true) - $started)->toBeLessThan(2.0)
            ->and([Node::all()->toArray(), NodeRole::all()->toArray(), NodeFact::all()->toArray()])->toBe($before)
            ->and(Operation::query()->count())->toBe(0);
    })->with(['missing facts' => false, 'stale facts' => true]);

    it('uses Gateway authority or a target grant and refuses neither without leaking assignments', function (string $authority): void {
        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'failed',
            'failed_step' => 'converge:private', 'error_code' => 'private.failure']);
        $this->caller->accessibleNodes()->detach();
        $gateway = role_list_node('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        if ($authority === 'gateway') {
            $this->caller->accessibleNodes()->attach($gateway);
        } elseif ($authority === 'target') {
            $this->caller->accessibleNodes()->attach($this->node);
        }
        $before = [Node::all()->toArray(), NodeRole::all()->toArray()];

        $response = $this->getJson('/api/v1/nodes/'.$this->node->id.'/roles');
        if ($authority === 'neither') {
            $response->assertForbidden()->assertJsonPath('error.code', 'node_access.required')
                ->assertJsonMissingPath('data');
            expect($response->getContent())->not->toContain('converge:private', 'private.failure');
        } else {
            $response->assertOk()->assertJsonPath('data.0.role', 'app-dev');
        }

        expect([Node::all()->toArray(), NodeRole::all()->toArray()])->toBe($before)
            ->and(Operation::query()->count())->toBe(0);
    })->with(['gateway', 'target', 'neither']);

    it('refuses an unknown numeric Node without creating an Operation', function (): void {
        $this->getJson('/api/v1/nodes/2147483647/roles')->assertNotFound()
            ->assertJsonPath('error.code', 'http.404')->assertJsonMissingPath('data');
        expect(Operation::query()->count())->toBe(0);
    });
});
