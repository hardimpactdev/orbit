<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\CreateProcessRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;
use Orbit\Sdk\Requests\Processes\UpdateProcessRequest;
use Orbit\Sdk\Requests\Schedules\CreateScheduleRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;
use Orbit\Sdk\Requests\Schedules\EnableScheduleRequest;
use Orbit\Sdk\Requests\Schedules\NodeScheduleTarget;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Processes\ProcessMutationResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleMutationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('transports each Process and Schedule Operation and preserves its admission key', function (): void {
    $schedule = '830fd68c-948b-4e46-a215-229c7f510246';
    $requests = [
        new CreateProcessRequest(new NodeProcessTarget(2), 'proof', 'systemd', ['/usr/bin/sleep', '300'], idempotencyKey: 'proof'),
        new UpdateProcessRequest(12, keepAlive: true, idempotencyKey: 'proof'),
        new StartProcessRequest(12, 'proof'), new StopProcessRequest(12, 'proof'), new RestartProcessRequest(12, 'proof'), new DestroyProcessRequest(12, 'proof'),
        new CreateScheduleRequest(new NodeScheduleTarget(2), 'proof', 'daily', 'true', idempotencyKey: 'proof'),
        new RunScheduleRequest($schedule, 'proof'), new EnableScheduleRequest($schedule, 'proof'), new DestroyScheduleRequest($schedule, 'proof'),
    ];
    $verbs = ['process.create', 'process.update', 'process.start', 'process.stop', 'process.restart', 'process.destroy', 'schedule.create', 'schedule.run', 'schedule.enable', 'schedule.destroy'];
    foreach ($requests as $index => $request) {
        $mock = new MockClient([$request::class => MockResponse::make(['data' => ['id' => 91, 'kind' => $verbs[$index], 'target_type' => 'process', 'target_id' => 12, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'cancel_requested' => false, 'attempts' => 0], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        $response = $connector->send($request)->dto();
        if ($response instanceof ProcessMutationResponse || $response instanceof ScheduleMutationResponse) {
            $response = $response->result;
        }
        expect($response)->toBeInstanceOf(OperationResponse::class)
            ->and($response->kind)->toBe($verbs[$index])
            ->and($request->headers()->get('Idempotency-Key'))->toBe('proof')
            ->and($response->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a846');
    }
});
