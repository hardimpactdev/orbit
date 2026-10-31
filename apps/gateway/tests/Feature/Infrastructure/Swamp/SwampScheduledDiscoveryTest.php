<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\ProjectSwampScheduledTerminal;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;
use Tests\Support\FakeSwampEventSocket;

function scheduledDiscoverySubscriber(FakeSwampEventSocket $socket, ?Closure $clock = null): SwampRunSubscriber
{
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['test.scheduled' => ['workflow' => 'operation-test-scheduled', 'projection' => DirectOperationProjection::class,
                'terminal_result' => ['job' => 'check', 'step' => 'result'],
                'scheduled_result' => ['workflow' => 'task-check-ci', 'workflow_id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230',
                    'terminal_result' => ['job' => 'checks', 'step' => 'result'], 'rows' => 'checks']]];
        }
    }]));

    return new SwampRunSubscriber($socket, new NullLogger, $clock);
}

function scheduledDiscoveryFeed(array $request, int $cursor = 7, string $workflow = '4a2d9dd5-9f83-41d4-b8df-8e46367ed230'): array
{
    return ['type' => 'commits.subscribe', 'id' => $request['id'], 'payload' => ['cursor' => $cursor, 'head' => $cursor, 'done' => false,
        'changes' => [['cursor' => $cursor, 'commit' => ['ops' => [['kind' => 'put', 'intent' => ['kind' => 'run.update',
            'run' => ['workflowId' => $workflow, 'runId' => 'b075eb23-7ac4-4487-80d1-57a038a92676']]]]]]]]];
}

function scheduledDiscoveryAttached(array $request): array
{
    return ['type' => 'run.attached', 'id' => $request['id'], 'payload' => ['runId' => $request['payload']['runId'], 'kind' => 'workflow-run']];
}

function scheduledDiscoveryTerminal(array $request): array
{
    return ['type' => 'event', 'id' => $request['id'], 'event' => ['seq' => 9, 'kind' => 'completed', 'run' => [
        'id' => $request['payload']['runId'], 'workflowName' => 'task-check-ci', 'status' => 'succeeded']]];
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture.events', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    Cache::flush();
});

it('discovers only a declared scheduled workflow and requires its terminal root notice before projection', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $subscriber = scheduledDiscoverySubscriber($socket);
    $subscriber->pass();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload'])->toBe(['cursor' => 0]);
    $socket->push(scheduledDiscoveryFeed($socket->sent[0], 3, 'd075eb23-7ac4-4487-80d1-57a038a92676'));
    $subscriber->pass();
    expect($socket->sent)->toHaveCount(1);
    $socket->push(scheduledDiscoveryFeed($socket->sent[0]));
    $subscriber->pass();
    expect(array_column($socket->sent, 'type'))->toBe(['commits.subscribe', 'run.attach']);
    Bus::assertNothingDispatched();
    $request = $socket->sent[1];
    $socket->push(scheduledDiscoveryTerminal($request));
    $subscriber->pass();
    Bus::assertNothingDispatched();
    $socket->push(scheduledDiscoveryAttached($request), scheduledDiscoveryTerminal($request), scheduledDiscoveryTerminal($request));
    $subscriber->pass();
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, 1);
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, fn ($job): bool => $job->workflowId === '4a2d9dd5-9f83-41d4-b8df-8e46367ed230'
        && $job->runId === 'b075eb23-7ac4-4487-80d1-57a038a92676' && $job->status === 'succeeded');
    expect(Operation::query()->count())->toBe(0);
});

it('recovers a saved cursor and undelivered exact root across process restart', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $first = new FakeSwampEventSocket;
    $subscriber = scheduledDiscoverySubscriber($first);
    $subscriber->pass();
    $first->push(scheduledDiscoveryFeed($first->sent[0]));
    $subscriber->pass();
    $subscriber->close();
    $second = new FakeSwampEventSocket;
    $replacement = scheduledDiscoverySubscriber($second);
    $replacement->pass();
    expect($second->sent[0]['payload'])->toBe(['cursor' => 7]);
    expect($second->sent[1]['payload'])->toBe(['runId' => 'b075eb23-7ac4-4487-80d1-57a038a92676', 'afterSeq' => 0]);
    $second->push(['type' => 'error', 'id' => $second->sent[1]['id'], 'error' => ['code' => 'not_found']]);
    $replacement->pass();
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, fn ($job): bool => $job->status === null);
    $replacement->close();
    $third = new FakeSwampEventSocket;
    scheduledDiscoverySubscriber($third)->pass();
    expect($third->sent)->toHaveCount(1);
    expect($third->sent[0]['payload'])->toBe(['cursor' => 7]);
});

it('keeps failed projection recoverable without timer history retries', function (): void {
    $socket = new FakeSwampEventSocket;
    $clock = (object) ['now' => 100.0];
    $subscriber = scheduledDiscoverySubscriber($socket, static fn (): float => $clock->now);
    $subscriber->pass();
    $socket->push(scheduledDiscoveryFeed($socket->sent[0]));
    $subscriber->pass();
    $attempts = 0;
    Bus::shouldReceive('dispatchSync')->andReturnUsing(function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('private-result-not-logged');
    });
    $request = $socket->sent[1];
    $socket->push(scheduledDiscoveryAttached($request), scheduledDiscoveryTerminal($request));
    $subscriber->pass();
    $clock->now = 10_000;
    $subscriber->pass();
    expect($attempts)->toBe(1);
    expect($socket->sent)->toHaveCount(2);
    $socket->push(scheduledDiscoveryTerminal($request));
    $subscriber->pass();
    expect($attempts)->toBe(2);
    $subscriber->close();
    $replacement = new FakeSwampEventSocket;
    scheduledDiscoverySubscriber($replacement)->pass();
    expect($replacement->sent[0]['payload'])->toBe(['cursor' => 7]);
    expect($replacement->sent)->toHaveCount(2);
});

it('reconnects a lagged feed from the saved cursor without scanning histories', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $subscriber = scheduledDiscoverySubscriber($socket, static fn (): float => $clock->now);
    $subscriber->pass();
    $socket->push(['type' => 'commits.subscribe', 'id' => $socket->sent[0]['id'], 'payload' => ['changes' => [], 'cursor' => 7, 'head' => 7, 'done' => false]],
        ['type' => 'error', 'id' => $socket->sent[0]['id'], 'error' => ['code' => 'commits_lagged']]);
    $subscriber->pass();
    $clock->now = 101;
    $subscriber->pass();
    expect(array_column($socket->sent, 'type'))->toBe(['commits.subscribe', 'commits.subscribe']);
    expect($socket->sent[1]['payload'])->toBe(['cursor' => 7]);
    Bus::assertNothingDispatched();
});

it('refuses compacted or unavailable discovery without resetting the cursor or inventing a scan', function (string $code): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $subscriber = scheduledDiscoverySubscriber($socket);
    $subscriber->pass();
    $socket->push(['type' => 'error', 'id' => $socket->sent[0]['id'], 'error' => ['code' => $code]]);
    $subscriber->pass();
    $subscriber->close();
    $subscriber->pass();
    expect($socket->sent)->toHaveCount(1);
    Bus::assertNothingDispatched();
})->with(['commits_compacted', 'commit_feed_unavailable', 'forbidden']);

it('rejects a malformed feed frame before advancing its durable cursor', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $subscriber = scheduledDiscoverySubscriber($socket);
    $subscriber->pass();
    $frame = scheduledDiscoveryFeed($socket->sent[0]);
    $frame['payload']['changes'][0]['cursor'] = 8;
    $socket->push($frame);
    $subscriber->pass();
    $replacement = new FakeSwampEventSocket;
    scheduledDiscoverySubscriber($replacement)->pass();
    expect($replacement->sent[0]['payload'])->toBe(['cursor' => 0]);
    expect($replacement->sent)->toHaveCount(1);
    Bus::assertNothingDispatched();
});
