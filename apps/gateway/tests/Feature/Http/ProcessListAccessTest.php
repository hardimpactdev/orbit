<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessUsageIndex;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\withServerVariables;

uses(LazilyRefreshDatabase::class);

function process_access_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

function process_access_owner(Node $node, string $type): Node|Instance
{
    if ($type === 'node') {
        return $node;
    }

    $project = Project::query()->create([
        'name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git',
        'default_branch' => 'main', 'root' => '.',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'development', 'checkout_path' => '/srv/app',
    ]);
}

function process_access_record(Node|Instance $owner): Process
{
    return $owner->processes()->create([
        'name' => 'worker', 'runtime' => 'systemd', 'runtime_config' => ['command' => 'php artisan queue:work'],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
}

function process_access_observations(): void
{
    Http::preventStrayRequests();
    $statuses = Mockery::mock(ProcessRuntimeStatusIndex::class);
    $statuses->shouldReceive('observations')->andReturn([]);
    app()->instance(ProcessRuntimeStatusIndex::class, $statuses);
    $usage = Mockery::mock(ProcessUsageIndex::class);
    $usage->shouldReceive('usage')->andReturn([]);
    app()->instance(ProcessUsageIndex::class, $usage);
}

/** @return TestResponse<Response> */
function process_access_get(Node $caller, string $path = '/api/v1/processes'): TestResponse
{
    return withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson($path);
}

describe('Process collection access', function (): void {
    it('hides Processes with an unrelated grant, reveals them with the owning Node grant and hides them after revocation', function (string $type): void {
        process_access_observations();
        $caller = process_access_node('caller', '10.44.0.9');
        $other = process_access_node('other', '10.44.0.3');
        $owning = process_access_node('owning', '10.44.0.4');
        $process = process_access_record(process_access_owner($owning, $type));
        $caller->accessibleNodes()->attach($other);

        process_access_get($caller)->assertOk()->assertJsonPath('data', []);

        $caller->accessibleNodes()->attach($owning);
        process_access_get($caller)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $process->id);

        $caller->accessibleNodes()->detach($owning);
        process_access_get($caller)->assertOk()->assertJsonPath('data', []);
    })->with(['Node-owned' => 'node', 'Instance-owned morph alias' => 'instance']);

    it('returns the normal collection refusal with 403 for a caller without any grant', function (): void {
        process_access_observations();
        $caller = process_access_node('caller', '10.44.0.9');
        process_access_record(process_access_node('owning', '10.44.0.4'));

        process_access_get($caller)->assertForbidden()->assertJsonPath('error', [
            'code' => 'node_access.required', 'message' => 'Node access is required.',
            'details' => ['consumer_node' => ['id' => $caller->id, 'name' => $caller->name], 'serving_node' => null],
        ]);
    });

    it('keeps the unfiltered fleet-wide list for Gateway authority', function (string $authority): void {
        process_access_observations();
        $gateway = process_access_node('gateway', '10.44.0.2');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $operator = process_access_node('operator', '10.44.0.9');
        $caller = $authority === 'gateway' ? $gateway : $operator;
        if ($authority === 'serving') {
            app(GatewayServingHost::class)->remember($operator);
        }
        if ($authority === 'grant') {
            $operator->accessibleNodes()->attach($gateway);
        }
        $nodeProcess = process_access_record(process_access_node('first', '10.44.0.3'));
        $instanceProcess = process_access_record(process_access_owner(process_access_node('second', '10.44.0.4'), 'instance'));

        $response = process_access_get($caller)->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonFragment(['id' => $nodeProcess->id])->assertJsonFragment(['id' => $instanceProcess->id]);
    })->with(['active Gateway Node' => 'gateway', 'recorded serving host' => 'serving', 'grant to Gateway' => 'grant']);
});

describe('targeted Process reads', function (): void {
    it('keeps owning Node authorization for targeted lists and logs', function (string $type): void {
        process_access_observations();
        $caller = process_access_node('caller', '10.44.0.9');
        $other = process_access_node('other', '10.44.0.3');
        $owning = process_access_node('owning', '10.44.0.4');
        $owner = process_access_owner($owning, $type);
        $process = process_access_record($owner);
        $caller->accessibleNodes()->attach($other);
        $list = '/api/v1/processes?target_type='.$type.'&target_id='.$owner->id;
        $logs = '/api/v1/processes/'.$process->id.'/logs';
        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldReceive('logs')->once()->withArgs(fn (Process $record, int $lines): bool => $record->id === $process->id && $lines === 100)->andReturn('worker output');
        app()->instance(ProcessRuntimeManager::class, $runtime);

        process_access_get($caller, $list)->assertForbidden()->assertJsonPath('error.code', 'node_access.required')->assertJsonPath('error.details.serving_node.id', $owning->id);
        process_access_get($caller, $logs)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

        $caller->accessibleNodes()->attach($owning);
        process_access_get($caller, $list)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $process->id);
        process_access_get($caller, $logs)->assertOk()->assertJsonPath('data.logs', 'worker output');

        $caller->accessibleNodes()->detach($owning);
        process_access_get($caller, $list)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        process_access_get($caller, $logs)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    })->with(['Node-owned' => 'node', 'Instance-owned morph alias' => 'instance']);
});
