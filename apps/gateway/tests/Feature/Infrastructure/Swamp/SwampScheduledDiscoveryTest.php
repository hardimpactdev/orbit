<?php

declare(strict_types=1);

use App\Infrastructure\Swamp\SwampScheduledResults;
use App\Jobs\Operations\ProjectSwampScheduledTerminal;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;
use Tests\Support\FakeSwampEventSocket;

function scheduledDiscoveryFeed(array $request, int $cursor = 7, string $workflow = '4a2d9dd5-9f83-41d4-b8df-8e46367ed230'): array
{
    return ['type' => 'commits.subscribe', 'id' => $request['id'], 'payload' => ['cursor' => $cursor, 'head' => $cursor, 'done' => false,
        'changes' => [['cursor' => $cursor, 'commit' => ['ops' => [['kind' => 'put', 'intent' => ['kind' => 'run.update',
            'run' => ['workflowId' => $workflow, 'runId' => 'b075eb23-7ac4-4487-80d1-57a038a92676']]]]]]]]];
}

function scheduledDiscoveryRead(SwampScheduledResults $reader, FakeSwampEventSocket $socket): void
{
    $reader->pass();
    $request = array_last($socket->sent);
    expect($request['type'])->toBe('workflow.get');
    expect($request['payload'])->toBe(['workflowIdOrName' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230']);
    $socket->push(['type' => 'workflow.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230', 'name' => 'task-check-ci',
    ]]]);
    $reader->pass();
}

beforeEach(function (): void {
    config(['swamp.read_token' => 'fixture.reader', 'swamp.run_token' => 'never-use-admission-token',
        'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    Cache::flush();
});

it('discovers only declared scheduled roots using read authority without run attachments', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    scheduledDiscoveryRead($reader, $socket);
    expect($socket->sent[1]['payload'])->toBe(['cursor' => 0]);
    $socket->push(scheduledDiscoveryFeed($socket->sent[1], 3, 'd075eb23-7ac4-4487-80d1-57a038a92676'));
    $reader->pass();
    Bus::assertNothingDispatched();
    $socket->push(scheduledDiscoveryFeed($socket->sent[1]));
    $reader->pass();
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, 1);
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, fn ($job): bool => $job->workflowId === '4a2d9dd5-9f83-41d4-b8df-8e46367ed230'
        && $job->runId === 'b075eb23-7ac4-4487-80d1-57a038a92676' && $job->status === null);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.get', 'commits.subscribe']);
    expect($socket->endpoints[0]->bearerToken)->toBe('fixture.reader');
});

it('reads a pending root again only after a new native commit or startup recovery', function (): void {
    $attempts = 0;
    Bus::shouldReceive('dispatchSync')->andReturnUsing(function () use (&$attempts): bool {
        $attempts++;

        return false;
    });
    $socket = new FakeSwampEventSocket;
    $clock = (object) ['now' => 100.0];
    $reader = new SwampScheduledResults($socket, new NullLogger, static fn (): float => $clock->now);
    scheduledDiscoveryRead($reader, $socket);
    $socket->push(scheduledDiscoveryFeed($socket->sent[1]));
    $reader->pass();
    $clock->now = 10_000;
    $reader->pass();
    $reader->pass();
    expect($attempts)->toBe(1);
    $socket->push(scheduledDiscoveryFeed($socket->sent[1], 8));
    $reader->pass();
    expect($attempts)->toBe(2);
    $reader->close();
    $next = new FakeSwampEventSocket;
    scheduledDiscoveryRead(new SwampScheduledResults($next, new NullLogger), $next);
    expect($attempts)->toBe(3);
    expect($next->sent[1]['payload'])->toBe(['cursor' => 8]);
});

it('removes a delivered root while retaining the saved cursor across process restart', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    scheduledDiscoveryRead($reader, $socket);
    $socket->push(scheduledDiscoveryFeed($socket->sent[1]));
    $reader->pass();
    $reader->close();
    $next = new FakeSwampEventSocket;
    scheduledDiscoveryRead(new SwampScheduledResults($next, new NullLogger), $next);
    expect($next->sent[1]['payload'])->toBe(['cursor' => 7]);
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, 1);
});

it('preserves a failed delivery in the same cursor checkpoint without timer retries', function (): void {
    Bus::shouldReceive('dispatchSync')->once()->andThrow(new RuntimeException('private-diagnostic'));
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    scheduledDiscoveryRead($reader, $socket);
    $socket->push(scheduledDiscoveryFeed($socket->sent[1]));
    $reader->pass();
    $reader->pass();
    $reader->close();
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $next = new FakeSwampEventSocket;
    scheduledDiscoveryRead(new SwampScheduledResults($next, new NullLogger), $next);
    expect($next->sent[1]['payload'])->toBe(['cursor' => 7]);
    Bus::assertDispatchedSync(ProjectSwampScheduledTerminal::class, fn ($job): bool => $job->runId === 'b075eb23-7ac4-4487-80d1-57a038a92676');
});

it('reconnects a lagged feed from the saved cursor without scanning histories', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger, static fn (): float => $clock->now);
    scheduledDiscoveryRead($reader, $socket);
    $socket->push(['type' => 'commits.subscribe', 'id' => $socket->sent[1]['id'], 'payload' => ['changes' => [], 'cursor' => 7, 'head' => 7, 'done' => false]],
        ['type' => 'error', 'id' => $socket->sent[1]['id'], 'error' => ['code' => 'commits_lagged']]);
    $reader->pass();
    $clock->now = 101;
    scheduledDiscoveryRead($reader, $socket);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.get', 'commits.subscribe', 'workflow.get', 'commits.subscribe']);
    expect($socket->sent[3]['payload'])->toBe(['cursor' => 7]);
    Bus::assertNothingDispatched();
});

it('refuses compacted or unavailable discovery without resetting its cursor', function (string $code): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    scheduledDiscoveryRead($reader, $socket);
    $socket->push(['type' => 'error', 'id' => $socket->sent[1]['id'], 'error' => ['code' => $code]]);
    $reader->pass();
    $reader->close();
    $reader->pass();
    expect($socket->sent)->toHaveCount(2);
    Bus::assertNothingDispatched();
})->with(['commits_compacted', 'commit_feed_unavailable', 'forbidden']);

it('rejects malformed feed data before advancing its saved cursor', function (): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    scheduledDiscoveryRead($reader, $socket);
    $frame = scheduledDiscoveryFeed($socket->sent[1]);
    $frame['payload']['changes'][0]['cursor'] = 8;
    $socket->push($frame);
    $reader->pass();
    $next = new FakeSwampEventSocket;
    scheduledDiscoveryRead(new SwampScheduledResults($next, new NullLogger), $next);
    expect($next->sent[1]['payload'])->toBe(['cursor' => 0]);
    Bus::assertNothingDispatched();
});

it('refuses discovery when the existing reader cannot read the exact workflow', function (string $refusal): void {
    Bus::fake([ProjectSwampScheduledTerminal::class]);
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    $reader->pass();
    $request = $socket->sent[0];
    $socket->push($refusal === 'forbidden'
        ? ['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'forbidden']]
        : ['type' => 'workflow.get', 'id' => $request['id'], 'payload' => ['data' => [
            'id' => $request['payload']['workflowIdOrName'], 'name' => 'another-workflow',
        ]]]);
    $reader->pass();
    $reader->pass();
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.get']);
    Bus::assertNothingDispatched();
})->with(['forbidden', 'identity']);
