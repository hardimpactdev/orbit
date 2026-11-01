<?php

declare(strict_types=1);

use App\Actions\Doctor\StartDoctorAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Schedule;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'doctor-dev', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.90', 'wireguard_ip' => '10.44.0.90']);
    $this->caller = Node::query()->create(['name' => 'doctor-caller', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.91', 'wireguard_ip' => '10.44.0.91']);
    $this->caller->accessibleNodes()->attach($this->node);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'private.fixture']);
});

it('admits one native root with immutable participants and no Gateway observation', function (): void {
    $operation = app(StartDoctorAction::class)->execute($this->caller, $this->node->id, [DoctorFamily::Node]);
    expect($operation->kind)->toBe('doctor.inspect')->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->input['families'])->toBe(['node'])
        ->and($operation->input['participant_node_ids'])->toBe([$this->node->id])
        ->and($operation->input['nodes'][0]['managed'])->toBeFalse()
        ->and($operation->input['nodes'][0]['resources'])->toHaveKey('node_checks');
    $declaration = app(DirectOperation::class)->definition('doctor.inspect');
    expect($declaration['workflow'])->toBe('operation-doctor-inspect')
        ->and($declaration)->not->toHaveKeys(['runs', 'executor', 'completion_contract', 'result_fields', 'children']);
});

it('refuses inaccessible Nodes before admission', function (): void {
    $this->caller->accessibleNodes()->detach();
    expect(fn () => app(StartDoctorAction::class)->execute($this->caller, $this->node->id, []))->toThrow(AuthorizationException::class);
    expect(Operation::query()->where('kind', 'doctor.inspect')->exists())->toBeFalse();
});

it('returns an asynchronous Doctor API response and preserves its access boundary', function (): void {
    $body = json_encode(['node_id' => $this->node->id, 'families' => ['node']], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/doctor', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->caller->wireguard_ip], $body)
        ->assertAccepted()->assertJsonPath('data.kind', 'doctor.inspect')->assertJsonPath('data.status', 'running');
    $this->caller->accessibleNodes()->detach();
    $this->call('POST', '/api/v1/doctor', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->caller->wireguard_ip], $body)
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
});

it('records native recovery scope without exposing it or creating step receipts', function (): void {
    $operation = native_doctor_operation($this->node, true);
    $native = ['nodes' => [['node_id' => $this->node->id, 'node_name' => $this->node->name, 'families' => [[
        'family' => 'node', 'checked' => 1, 'issues' => [['code' => 'node.platform_mismatch', 'kind' => 'drift', 'resource_id' => $this->node->id, 'resource_name' => $this->node->name, 'summary' => 'Node platform does not match.', 'expected' => 'linux', 'observed' => 'darwin']],
        'observed_resources' => [$this->node->id], 'observed_checks' => [], 'observed_inventories' => [],
    ]]]]];
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => ['report' => $native]];
    $result = app(DoctorProjection::class)->apply($operation, $data);
    expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->result['report']['healthy'])->toBeFalse()
        ->and($result->result['report']['summary']['drift'])->toBe(1)
        ->and($result->result['report']['nodes'][0]['families'][0])->not->toHaveKey('observed_resources')
        ->and(ProblemFingerprint::query()->count())->toBe(1);
    app(DoctorProjection::class)->apply($operation, $data);
    expect(ProblemFingerprint::query()->count())->toBe(1)->and(ProblemFingerprint::query()->first()->occurrences)->toBe(1);
});

it('commits native log signals and cursors together and ignores stale native cursors', function (): void {
    $collector = app(ProblemCollector::class);
    $first = ['path' => '/var/log/orbit/laravel.log', 'inode' => 11, 'offset' => 90];
    $collector->recordLogs(['cursor' => $first, 'signals' => []]);
    $next = [...$first, 'offset' => 150];
    $collector->recordLogs(['cursor' => $next, 'signals' => [['exception_class' => 'RuntimeException', 'frame' => 'app/Example.php:Example::run', 'request_id' => 'request-safe', 'recorded_at' => '2026-10-31 07:00:00']]], $first);
    expect(ProblemCollectorState::query()->first()->log_offset)->toBe(150)->and(ProblemFingerprint::query()->count())->toBe(1);
    $collector->recordLogs(['cursor' => [...$first, 'offset' => 120], 'signals' => []], $first);
    expect(ProblemCollectorState::query()->first()->log_offset)->toBe(150);
});

it('keeps Schedule commands private and opts into fixed terminal projection', function (): void {
    $schedule = Schedule::query()->create(['id' => '0192e29b-9fd8-7c96-8e38-f863234d7112', 'target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id, 'name' => 'doctor-safe', 'command' => 'private schedule sentinel', 'calendar' => '*:0/5', 'timeout_seconds' => 30, 'desired_timer_state' => 'enabled']);
    $operation = app(StartDoctorAction::class)->execute($this->caller, $this->node->id, [DoctorFamily::Schedule]);
    expect(json_encode(app(DoctorProjection::class)->input($operation)))->not->toContain('private schedule sentinel');
    expect($operation->input)->not->toHaveKeys(['private_bindings', 'database_servers', 'schedules']);
    expect(app(DirectOperation::class)->definition('doctor.inspect'))->toMatchArray([
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'report', 'step' => 'result'],
    ]);

});

it('exposes expectations only to their admitted participant and keeps final projection root-only', function (): void {
    $operation = native_doctor_operation($this->node, false);
    $operation->input = ['participant_node_ids' => [$this->node->id, $this->caller->id],
        'nodes' => [['id' => $this->node->id, 'resources' => ['processes' => [['id' => 40]]]], ['id' => $this->caller->id, 'resources' => ['processes' => [['id' => 41]]]]],
        'logs' => ['log_directory' => '/private/gateway/logs']];
    $projection = app(DoctorProjection::class);
    $view = $projection->publicInput($operation, $this->caller->id);
    expect($view['nodes'][0]['resources'])->toEqual((object) [])->and($view['nodes'][1]['resources'])->toBe(['processes' => [['id' => 41]]])->and($view)->not->toHaveKey('logs');
    expect(fn () => $projection->publicInput($operation, 999999))->toThrow(ResourceOperationException::class);
});

it('recovers a native read failure without clearing retired numeric-resource checks', function (): void {
    $scope = 'node:'.$this->node->id;
    $family = ['family' => 'node', 'checked' => 3, 'issues' => [[
        'code' => 'node.inspection_failed', 'kind' => 'unverifiable', 'resource_id' => $scope, 'resource_name' => $this->node->name,
        'summary' => 'Native read unavailable.', 'expected' => 'verifiable', 'observed' => 'unverifiable',
    ]], 'observed_resources' => [], 'observed_checks' => [], 'observed_inventories' => []];
    $projection = app(DoctorProjection::class);
    $apply = function (array $row) use ($projection): void {
        $operation = native_doctor_operation($this->node, true);
        $projection->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
            'result' => ['report' => ['nodes' => [['node_id' => $this->node->id, 'node_name' => $this->node->name, 'families' => [$row]]]]]]);
    };
    $apply($family);
    $failure = ProblemFingerprint::query()->sole();
    $family['issues'][0]['resource_id'] = $this->node->id;
    $apply($family);
    $this->travel(1)->minutes();
    $family['issues'] = [];
    $family['observed_checks'] = [$scope => ['node.inspection_failed']];
    $apply($family);
    expect($failure->refresh()->evidence['resolution'] ?? null)->toBe('fresh_doctor_success');
    expect(ProblemFingerprint::query()->whereKeyNot($failure->id)->sole()->evidence)->not->toHaveKey('resolved_at');
});

function native_doctor_operation(Node $node, bool $collect): Operation
{
    return Operation::query()->create(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['collect_problems' => $collect], 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'status' => 'queued', 'sequence' => 1]);
}
