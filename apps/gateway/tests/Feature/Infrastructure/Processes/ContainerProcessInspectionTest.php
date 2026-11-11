<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\GatewayWorkflowGrantCheck;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\ContainerProcessInspection;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Node;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(LazilyRefreshDatabase::class);

/** Run the production TypeScript emitter, with native keys derived from its resource-write arguments.
 * @return array{request:string,modelId:string,value:string,vaulted:list<array{vault:string,key:string}>,reference:array<string,mixed>}
 */
function emittedProcessInspection(string $action): array
{
    static $fixtures = [];
    if (! isset($fixtures[$action])) {
        $command = new SymfonyProcess(['deno', 'run', '--allow-read', '--allow-write',
            base_path('../../swamp/tests/fixtures/process-inspection.ts'), $action === 'logs' ? 'private journal token' : '']);
        $command->mustRun();
        $data = ValidatedData::object(json_decode($command->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $vaulted = $data['vaulted'];
        if (! is_array($vaulted)) {
            throw new LogicException('Missing emitted vault keys.');
        }
        $emitted = ValidatedData::stringMap($vaulted[0]);
        $fixtures[$action] = [
            'request' => ValidatedData::string($data['request']), 'modelId' => ValidatedData::string($data['modelId']),
            'value' => ValidatedData::string($data['value']), 'reference' => ValidatedData::object($data['reference']),
            'vaulted' => [['vault' => $emitted['vault'], 'key' => $emitted['key']]],
        ];
    }

    return $fixtures[$action];
}

/** @return array{Process,ProcessTarget} */
function processInspectionTarget(): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'inspection-fixture', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '192.0.2.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $target = new ProcessTarget($node, 'orbit', '/srv/workspace', container: [
        'instance_id' => 1, 'project' => 'orbit-instances', 'name' => 'instance-1',
    ]);

    return [(new Process)->forceFill(['id' => 99, 'name' => 'worker']), $target];
}

afterEach(function (): void {
    Str::createUuidsNormally();
    app(SwampConnection::class)->release();
});

/** Feed the real reader one completed run, its capture and its exact vault value; no network is used. */
final class ProcessInspectionReadSocket implements WebSocketClient
{
    /** @var list<array<string,mixed>> */
    public array $frames = [];

    /** @param array{vault:string,key:string} $reference */
    public function __construct(
        private readonly string $request,
        private readonly string $vault,
        private readonly string $key,
        private readonly string $value,
        private readonly array $reference,
    ) {
        config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token',
            'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
        Str::createUuidsUsing(fn () => Uuid::fromString($this->request));
        app()->instance(WebSocketClient::class, $this);
    }

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

    public function close(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function send(array $message): void
    {
        $this->frames[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        $frame = end($this->frames);
        if ($frame === false) {
            throw new LogicException('No Swamp request was sent.');
        }
        $run = ['id' => '00000000-0000-4000-8000-000000000001', 'workflowName' => 'operation-process-container', 'status' => 'succeeded',
            'inputs' => ['kind' => 'process.inspect', 'intent' => ['read' => ['request' => $this->request]]],
            'jobs' => [['name' => 'process', 'steps' => [['name' => 'Read Process result', 'status' => 'succeeded',
                'outputs' => ['state' => 'active', 'inspection' => $this->reference]]]]]];

        return match ($frame['type']) {
            'workflow.run' => [['id' => $frame['id'], 'type' => 'event', 'event' => ['kind' => 'completed', 'run' => $run]]],
            'workflow.history.get' => [['id' => $frame['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]],
            'vault.read-secret' => [['id' => $frame['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => [
                'vaultName' => $this->vault, 'secretKey' => $this->key, 'vaultType' => 'local_encryption', 'value' => $this->value,
            ]]]],
            default => throw new LogicException('Unexpected Swamp request.'),
        };
    }
}

it('grants every key emitted by a container Process read', function (string $action): void {
    $fixture = emittedProcessInspection($action);
    $emitted = $fixture['vaulted'][0];
    $policy = ValidatedData::object(json_decode(File::get(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR));
    expect((new GatewayWorkflowGrantCheck)->execute(app(DirectOperation::class)->grantDefinitions(), $policy))->toBeGreaterThan(0);
    $grants = $policy['vaults'];
    if (! is_array($grants)) {
        throw new LogicException('Missing grant list.');
    }
    expect(array_any($grants, function (mixed $entry) use ($emitted): bool {
        $grant = ValidatedData::object($entry);

        return $grant['vault'] === $emitted['vault']
            && in_array('write', ValidatedData::stringList($grant['actions']), true)
            && in_array('read', ValidatedData::stringList($grant['actions']), true)
            && preg_match('~'.ValidatedData::string($grant['key_pattern']).'~D', $emitted['key']) === 1;
    }))->toBeTrue();
    expect($fixture['reference']['inspection'])->toBe(['vault' => $emitted['vault'], 'key' => $emitted['key']]);
})->with(['status', 'logs']);

it('reads exactly the native key emitted by a container Process read', function (string $action): void {
    $fixture = emittedProcessInspection($action);
    $emitted = $fixture['vaulted'][0];
    $socket = new ProcessInspectionReadSocket($fixture['request'], $emitted['vault'], $emitted['key'], $fixture['value'], $emitted);
    [$process, $target] = processInspectionTarget();

    expect((new ContainerProcessInspection($socket))->read($process, $target, $action, 100))
        ->toBe(json_decode($fixture['value'], true, flags: JSON_THROW_ON_ERROR));
    expect(array_column($socket->frames, 'type'))->toBe(['workflow.run', 'workflow.history.get', 'vault.read-secret']);
    expect($socket->frames[2]['payload'])->toBe(['vaultName' => $emitted['vault'], 'secretKey' => $emitted['key']]);
})->with(['status', 'logs']);

it('refuses an inspection reference outside the exact native key and request', function (string $mutation): void {
    $fixture = emittedProcessInspection('logs');
    $reference = ['vault' => $fixture['vaulted'][0]['vault'], 'key' => $fixture['vaulted'][0]['key']];
    $reference['key'] = match ($mutation) {
        'old two segments' => str_replace('-inspection-inspection-inspection-', '-inspection-inspection-', $reference['key']),
        'another request' => str_replace($fixture['request'], '00000000-0000-4000-8000-000000000002', $reference['key']),
        'malformed model UUID' => str_replace($fixture['modelId'], str_repeat('-', 36), $reference['key']),
        'extra prefix' => 'other-'.$reference['key'],
        'extra suffix' => $reference['key'].'-other',
        'trailing newline' => $reference['key']."\n",
        default => $reference['key'],
    };
    if ($mutation === 'another vault') {
        $reference['vault'] = 'other-vault';
    }
    $socket = new ProcessInspectionReadSocket($fixture['request'], $fixture['vaulted'][0]['vault'], $fixture['vaulted'][0]['key'], $fixture['value'], $reference);
    [$process, $target] = processInspectionTarget();

    expect(fn () => (new ContainerProcessInspection($socket))->read($process, $target, 'logs', 100))
        ->toThrow(ResourceOperationException::class, 'The container Process could not be read.');
    expect(array_column($socket->frames, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
})->with(['old two segments', 'another request', 'malformed model UUID', 'extra prefix', 'extra suffix', 'trailing newline', 'another vault']);
