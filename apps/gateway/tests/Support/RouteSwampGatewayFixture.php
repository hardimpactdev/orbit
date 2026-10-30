<?php

declare(strict_types=1);

namespace Tests\Support;

require_once __DIR__.'/CompletesRouteOperations.php';

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationTransport;
use App\Domain\Operations\OperationTransports;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Routes\RoutePublicationPrimitive;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** The real Swamp Route workflows call this test's Gateway kernel. Only Node transports are fake. */
final class RouteSwampGatewayFixture
{
    public readonly FakeAgentCommandRunner $agents;

    public readonly RouteForeignChildProof $foreign;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int,resource> */
    private array $pipes = [];

    private int $dispatches = 0;

    /** @var array<int,array{status:int,body:array<string,mixed>}> */
    private array $claims = [];

    /** @var array<int,array<string,array<string,mixed>>> */
    private array $stepResults = [];

    /** @var array<int,array<string,array{name:string,status:string}>> */
    private array $steps = [];

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public ?\Closure $beforeRequest = null;

    private string $buffer = '';

    private Node $worker;

    public function __construct(private TestCase $test, bool $nativeChildren = false, private bool $httpRuns = false)
    {
        Event::fake([RecordBroadcast::class]);
        Node::query()->where('platform', 'linux')->whereNull('architecture')->update(['architecture' => 'x86_64']);
        config(['swamp.direct_writers' => ['route.workload_verify' => 'direct', 'route.public_verify' => 'direct'], 'swamp.run_token' => 'fixture.fake', 'swamp.route_publication_writers' => ['route.caddy_converge' => 'direct', 'route.private_dns_publish' => 'direct']]);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
            'kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        ]]];
        app()->instance(WebSocketClient::class, $socket);
        RouteForeignChildKinds::register(simulateNodeChildren: ! $nativeChildren);
        $this->foreign = new RouteForeignChildProof;
        app()->instance(RouteForeignChildProof::class, $this->foreign);
        if (! $nativeChildren) {
            \bind_task_node_reachability();
            app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
            {
                public function rootCertificate(): string
                {
                    return 'fixture-public-root';
                }

                public function sign(string $hostname, string $certificateRequest): string
                {
                    throw new RuntimeException('Fixture leaves are current.');
                }
            });
            app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
            {
                public function resolve(Node $node): ManagedUserAccount
                {
                    return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
                }
            });
            $this->agents = new FakeAgentCommandRunner;
            foreach ([
                'certificate.request-leaf' => ['state' => 'current'], 'certificate.inspect-leaf' => ['present' => true], 'certificate.remove-leaf' => [],
                'caddy.publish' => ['result' => 'published'], 'firewall.rules' => ['matches' => true], 'firewall.route-lan' => ['matches' => true],
                'instance.grant-development-caddy-access' => ['unreadable' => []], 'php.inspect-shared-pools' => ['versions' => [], 'missing_pools' => []],
                'php.converge-shared-pools' => ['changed' => false, 'missing_pools' => []], 'route.verify-certificate' => [], 'route.verify-http' => [],
            ] as $name => $receipt) {
                $this->agents->answer($name, $receipt);
            }
            app()->instance(AgentCommandRunner::class, $this->agents);
        } else {
            $this->agents = app(AgentCommandRunner::class);
        }
        app()->instance(OperationTransports::class, new OperationTransports([...OperationTransports::definitions(), 'route-dns' => RouteFixtureDnsTransport::class]));
        $gateway = Node::query()->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway))->firstOrFail();
        $this->worker = Node::query()->create(['name' => 'route-fixture-worker', 'wireguard_ip' => '10.254.254.251', 'status' => 'active', 'public_ssh_host' => '192.0.2.251']);
        $this->worker->accessibleNodes()->attach($gateway);
        config(['operation_executor.worker_node_ids' => [$this->worker->id], 'dependency_detection.services_node_id' => $this->worker->id]);
        $definitions = OperationKinds::definitions();
        foreach ($definitions as $definition) {
            if (is_array($definition)) {
                foreach (['execution', 'completion', 'admission'] as $field) {
                    if (isset($definition[$field])) {
                        app()->singletonIf($definition[$field]);
                    }
                }
            }
        }
        foreach (glob(app_path('Domain/*/operation-commands.php')) as $file) {
            foreach (require $file as $class) {
                app()->singletonIf($class);
            }
        }
        foreach (OperationTransports::definitions() as $class) {
            app()->singletonIf($class);
        }
        foreach ([OperationExecutionContract::class, OperationChildren::class, OperationExecutors::class, SwampOperationExecutor::class] as $class) {
            app()->instance($class, app($class));
        }
    }

    public function finish(TestResponse $admission): Operation
    {
        $admission->assertAccepted();
        $operation = Operation::query()->findOrFail($admission->json('data.id'));
        $this->run($operation);

        return $operation->refresh();
    }

    public function run(Operation $root): void
    {
        $previous = request()->server->get('REMOTE_ADDR');
        $this->test->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
        try {
            for ($attempt = 0; $attempt < 200; $attempt++) {
                $root->refresh();
                if ($root->status->terminal()) {
                    if ($this->foreign->observationFailure !== null) {
                        throw $this->foreign->observationFailure;
                    }

                    return;
                }
                $pending = Operation::query()->whereIn('status', ['queued', 'running'])->where('kind', '!=', $root->kind)->orderByDesc('id')->first();
                $operation = $pending ?? $root;
                $this->dispatch($operation);
            }
            throw new RuntimeException('Route fixture did not settle its children.');
        } finally {
            $this->test->withServerVariables(['REMOTE_ADDR' => $previous]);
        }
    }

    private function dispatch(Operation $operation): void
    {
        if ($this->process === null) {
            $swamp = getenv('ORBIT_SWAMP_CHECKOUT') ?: dirname(base_path(), 3).'/orbit-swamp';
            $deno = getenv('DENO_BIN') ?: (is_executable(dirname(base_path(), 3).'/bin/deno') ? dirname(base_path(), 3).'/bin/deno' : 'deno');
            $this->process = proc_open([$deno, 'run', '--no-check', '--allow-read='.$swamp, $swamp.'/extensions/behaviors/operations/routes/gateway_fixture.ts'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, $swamp);
            if (! is_resource($this->process)) {
                throw new RuntimeException('Could not start the Swamp fixture.');
            }
        }
        fwrite($this->pipes[0], json_encode(['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'run_id' => $operation->state['swamp_run_id'] ?? (string) Str::uuid(), 'kind' => $operation->kind, 'direct' => DirectOperation::owns($operation), 'worker' => 'fixture-'.$operation->id.'-'.++$this->dispatches,
            'foreign' => (app(OperationKinds::class)->definition($operation->kind)['completion'] ?? null) === RouteForeignChildCompletion::class], JSON_THROW_ON_ERROR)."\n");
        while (($line = fgets($this->pipes[1])) !== false) {
            $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if ($message['type'] === 'error') {
                throw new RuntimeException($message['message'].' '.json_encode(array_slice($this->requests, -3)));
            }
            if ($message['type'] === 'done') {
                return;
            }
            $body = $message['body'] ?? [];
            if (($body['input']['action'] ?? null) === 'open' && str_starts_with($body['input']['stage'] ?? '', 'await-')) {
                $this->test->travel(35)->seconds();
            }
            ($this->beforeRequest ?? static function (): void {})($message['method'], $message['path'], $body);
            $response = $this->request($message['method'], $message['path'], $body);
            $this->requests[] = ['method' => $message['method'], 'path' => $message['path'], 'action' => $body['input']['action'] ?? null, 'stage' => $body['input']['stage'] ?? null, 'status' => $response['status'], 'error' => $response['body']['error']['code'] ?? null];
            fwrite($this->pipes[0], json_encode($response, JSON_THROW_ON_ERROR)."\n");
        }
        throw new RuntimeException('Route fixture stopped: '.stream_get_contents($this->pipes[2]));
    }

    /** @param array<string, mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    private function request(string $method, string $path, array $body): array
    {
        if ($this->httpRuns && $method === 'POST' && str_ends_with($path, '/run')) {
            $response = $this->test->postJson($path, $body);

            return ['status' => $response->status(), 'body' => $response->json()];
        }
        if (preg_match('~/operation-executor/(\d+)/heartbeat$~', $path, $heartbeat) && isset($this->claims[(int) $heartbeat[1]])) {
            // Claim already proves the lease. Generic heartbeat persistence is covered by its HTTP suite.
            $claimed = $this->claims[(int) $heartbeat[1]];
            unset($this->claims[(int) $heartbeat[1]]);

            return $claimed;
        }
        if (preg_match('~/fixture/route-verification/(\d+)$~', $path, $probe)) {
            return ['status' => 200, 'body' => ['data' => $this->foreign->run(Operation::query()->findOrFail((int) $probe[1]), ['proof' => 'ready'])]];
        }
        if (preg_match('~/direct-operations/(\d+)~', $path, $directMatch)) {
            $operation = Operation::query()->findOrFail((int) $directMatch[1]);
            $direct = app(DirectOperation::class);
            $runId = $body['run_id'] ?? '';
            if ($method === 'GET') {
                parse_str(parse_url($path, PHP_URL_QUERY) ?: '', $query);
                $runId = $query['run_id'] ?? '';
            }
            $direct->assertRun($operation, $runId);
            if (str_ends_with($path, '/primitive')) {
                $value = (app(OperationKinds::class)->definition($operation->kind)['completion'] ?? null) === RouteForeignChildCompletion::class
                    ? $this->foreign->run($operation, ['proof' => 'ready']) : $direct->primitive($operation)->execute($operation);

                return ['status' => 200, 'body' => ['data' => app(OperationExecutionContract::class)->result($operation, $value)]];
            }
            if ($method === 'POST') {
                $operation = $direct->projection($operation)->apply($operation, $body);
            }

            return ['status' => 200, 'body' => ['data' => ['id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
                'input' => $method === 'GET' ? $direct->projection($operation)->input($operation) : [], 'status' => $operation->status->value,
                'result' => $operation->result, 'cancel_requested' => $operation->cancel_requested_at !== null]]];
        }
        $executor = app(SwampOperationExecutor::class);
        try {
            $status = 200;
            if (str_ends_with($path, '/claim')) {
                $value = $executor->claim($body['kind'], $body['worker'], $this->worker->id);
            } elseif (preg_match('~/operation-executor/(\d+)/(.*)$~', $path, $match)) {
                $op = Operation::query()->findOrFail((int) $match[1]);
                $value = match ($match[2]) {
                    'heartbeat' => $executor->heartbeat($op, $body['worker'], $this->worker->id),
                    'step' => $this->step($op, $body, $executor),
                    'complete' => $executor->complete($op, $body, $this->worker->id),
                    'run' => $executor->run($op, $body, $this->worker->id),
                    'wait' => $executor->wait($op, $body['worker'], $body['keys'], $this->worker->id),
                    'children' => $method === 'GET' ? app(OperationChildren::class)->receipts($op) : $executor->child($op, $body, $this->worker->id),
                    default => $executor->receipt($op, substr($match[2], strlen('receipts/')), $this->worker->id),
                };
                if ($match[2] === 'children' && $method === 'POST') {
                    $status = $value['created'] ? 202 : 200;
                    $value = $value['operation'];
                }
            } elseif (preg_match('~/operations/(\d+)$~', $path, $match)) {
                $value = Operation::query()->findOrFail((int) $match[1]);
            } else {
                throw new RuntimeException('Unknown fixture endpoint '.$path);
            }
            if ($value instanceof Operation && (str_ends_with($path, '/step') || str_ends_with($path, '/complete'))) {
                return ['status' => $status, 'body' => ['data' => []]];
            }
            if ($value instanceof Operation) {
                $wait = $value->state['executor_wait']['children'] ?? [];
                $value = ['id' => $value->id, 'kind' => $value->kind, 'input' => $value->input, 'status' => $value->status->value, 'error_code' => $value->error_code, 'result' => $value->result,
                    'cancel_requested' => $value->cancel_requested_at !== null,
                    'steps' => array_values($this->steps[$value->id] ?? []),
                    'node_id' => app(OperationChildren::class)->nodeId($value), 'worker' => $value->worker,
                    'parent_operation_id' => $value->parent_operation_id, 'child_key' => $value->child_key,
                    'step_results' => $this->stepResults[$value->id] ?? [],
                    'waiting_for' => array_map(static fn (array $tuple): array => ['key' => $tuple['key'], 'id' => $tuple['id']], $wait),
                    'execution_context' => (str_ends_with($path, '/claim') || str_ends_with($path, '/heartbeat')) ? (app(OperationExecutionContract::class)->execution($value)?->context($value) ?? ['descriptors' => []]) : ['descriptors' => []]];
            }

            if (str_ends_with($path, '/run') && $value === []) {
                $value = (object) [];
            }
            $response = ['status' => $status, 'body' => ['data' => $value]];
            if (str_ends_with($path, '/claim') && is_array($value)) {
                $this->claims[$value['id']] = $response;
            }

            return $response;
        } catch (ResourceOperationException $exception) {
            return ['status' => $exception->status, 'body' => ['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]]];
        }
    }

    /** The family test stores progress events in memory. Keyed runs, child proofs and the final
     * completion step use the real Gateway store; generic event persistence has its own HTTP tests.
     *
     * @param  array<string,mixed>  $body
     */
    private function step(Operation $operation, array $body, SwampOperationExecutor $executor): Operation
    {
        $name = $body['name'];
        $this->steps[$operation->id][$name] = ['name' => $name, 'status' => $body['status']];
        if ($body['status'] === 'succeeded') {
            $this->stepResults[$operation->id][$name] = $body['result'];
        }
        $definition = app(OperationKinds::class)->definition($operation->kind);
        $proof = $definition['completion_contract']['step'];
        if ($body['status'] === 'succeeded' && $name === $proof) {
            return $executor->step($operation, $body, $this->worker->id);
        }

        return $operation;
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
        }
    }
}

final readonly class RouteFixtureDnsTransport implements OperationTransport, RoutePublicationPrimitive
{
    public function __construct(private ?\Closure $publish = null) {}

    public function schema(Operation $operation, array $run): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['records', 'catalog'], 'properties' => ['records' => ['type' => 'string'], 'catalog' => ['type' => 'string']]];
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->publish($operation, $input);
    }

    public function publish(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($this->publish !== null) {
            try {
                ($this->publish)($operation, $input);
            } catch (ResourceOperationException $exception) {
                return ['error_code' => $exception->errorCode];
            }
        }

        return ['outcome' => 'published', 'data' => ['ready' => true, 'digest' => hash('sha256', $input['catalog'])], 'ready' => true];
    }
}
