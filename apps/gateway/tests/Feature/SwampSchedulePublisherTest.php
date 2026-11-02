<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampSchedulePublisher;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/fixture/public-ca.pem', 'swamp.run_token' => 'fixture.run']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.trigger.set', 'id' => $sent['id'],
        'payload' => ['data' => ['workflowName' => $sent['payload']['workflowName'], 'entry' => ['schedule' => $sent['payload']['schedule'], 'inputs' => json_decode(json_encode($sent['payload']['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)]]]]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->workflow = 'a37dfa1b-1633-475f-90c7-dfd9c83008ca';
});

it('publishes nested captured inputs as native data without starting a run or an Operation', function (): void {
    $input = ['revision' => 7, 'projects' => [['id' => 8, 'groups' => [['id' => 9, 'eligible' => true]]]], 'pending' => []];
    app(SwampSchedulePublisher::class)->publish($this->workflow, '*/5 * * * *', $input);
    expect($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['type'])->toBe('workflow.trigger.set')
        ->and($this->socket->sent[0]['payload'])->toBe(['workflowName' => 'maintenance-projects', 'schedule' => '*/5 * * * *', 'inputs' => $input])
        ->and($this->socket->closed)->toBeTrue();
    expect(Operation::query()->count())->toBe(0);
});

it('refuses an undeclared workflow before sending native state', function (): void {
    expect(fn () => app(SwampSchedulePublisher::class)->publish('e075eb23-7ac4-4487-80d1-57a038a92676', '* * * * *', []))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
});

it('does not treat a refusal, mismatched input or lost response as accepted publication', function (string $cause): void {
    $this->socket->reply = static function (array $sent) use ($cause): array {
        if ($cause === 'lost') {
            return [];
        }
        if ($cause === 'refused') {
            return [['type' => 'error', 'id' => $sent['id'], 'error' => ['code' => 'forbidden', 'message' => 'private diagnostic']]];
        }

        return [['type' => 'workflow.trigger.set', 'id' => $sent['id'], 'payload' => ['data' => ['workflowName' => $sent['payload']['workflowName'], 'entry' => ['schedule' => '* * * * *', 'inputs' => ['revision' => 99]]]]]];
    };
    expect(fn () => app(SwampSchedulePublisher::class)->publish($this->workflow, '* * * * *', ['revision' => 7]))
        ->toThrow(ResourceOperationException::class, 'not confirmed');
    expect($this->socket->sent)->toHaveCount(1)->and($this->socket->closed)->toBeTrue();
})->with(['lost', 'refused', 'mismatch']);

it('accepts the real JSON representation of floating point and empty nested input', function (): void {
    app(SwampSchedulePublisher::class)->publish($this->workflow, '* * * * *', ['threshold' => 1.0, 'nested' => ['empty' => []]]);
    expect($this->socket->sent)->toHaveCount(1)->and($this->socket->closed)->toBeTrue();
});
