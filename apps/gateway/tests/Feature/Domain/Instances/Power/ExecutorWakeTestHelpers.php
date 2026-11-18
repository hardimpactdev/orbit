<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Projects\ProjectType;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Ssh\SshExecutor;
use App\Jobs\Operations\ReconcileExecutorWakes;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Testing\TestResponse;
use Psr\Log\NullLogger;

require_once dirname(__DIR__).'/ContainerInstanceHelpers.php';

/** Real admission/signing/listener, fake publish at the event boundary and a clocked in-memory result queue. */
function executorWakeInstance(): Instance
{
    $test = test();
    Queue::fake();
    Http::preventStrayRequests();
    config(['orbit.executor.wake_enabled' => true, 'orbit.executor.wait_ms' => 500,
        'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $test->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $test->mock(SshExecutor::class)->shouldNotReceive('execute');
    $test->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    app()->forgetInstance(RealtimeConnection::class);
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'stopped']);
    $test->resultQueue = new class implements ExecutorResultQueue
    {
        public float $now = 1000.0;

        /** @var array<string, list<string>> */
        public array $lists = [];

        /** @var list<float> */
        public array $timeouts = [];

        public ?Closure $arriving = null;

        public function push(string $key, string $line, int $expirySeconds): void
        {
            $this->lists[$key][] = $line;
        }

        public function pop(array $keys, float $timeoutSeconds): ?array
        {
            $this->timeouts[] = $timeoutSeconds;
            if ($this->arriving !== null) {
                $arriving = $this->arriving;
                $this->arriving = null;
                $arriving();
            }
            foreach ($keys as $key) {
                if (($this->lists[$key] ?? []) !== []) {
                    return [$key, array_shift($this->lists[$key])];
                }
            }
            $this->now += $timeoutSeconds;

            return null;
        }
    };
    $queue = $test->resultQueue;
    app()->instance(ExecutorResultWait::class, new ExecutorResultWait($queue, static fn (): float => $queue->now));
    $test->results = new ExecutorResults($queue, new NullLogger, app(CommandActivityInputSanitizer::class));
    $test->publisher = new class(app('events'), [ExecutorOrderBroadcast::class]) extends EventFake
    {
        public ?Closure $publishing = null;

        public function dispatch($event, $payload = [], $halt = false)
        {
            if ($event instanceof ExecutorOrderBroadcast && $this->publishing !== null) {
                ($this->publishing)($event);
            }

            return parent::dispatch($event, $payload, $halt);
        }
    };
    Event::swap($test->publisher);

    return $instance;
}

function executorWakeVisit(Instance $instance, string $uri = '/dashboard?tab=1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->withHeader('X-Forwarded-Uri', $uri)
        ->get('/api/v1/runtime-activations/app-instance/'.$instance->id);
}

function executorWakeSweep(): void
{
    app()->call([new ReconcileExecutorWakes, 'handle']);
}

/** @param array<string, mixed> $line */
function executorWakeReply(Operation $operation, array $line): void
{
    if (in_array($line['status'] ?? null, ['done', 'partial', 'failed'], true)) {
        $line = ['action' => 'wake', 'target' => (string) $operation->target_id, ...$line];
    }
    $reply = ExecutorReply::fromEvent(['operation' => (string) $operation->id,
        'line' => json_encode(['operation' => (string) $operation->id, ...$line], JSON_THROW_ON_ERROR)]);
    expect($reply)->toBeInstanceOf(ExecutorReply::class);
    test()->results->receive($operation->node_id, $reply);
}
