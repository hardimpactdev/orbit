<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

it('refuses retired lifecycle planning before recording or dispatching an Operation', function (string $verb): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $error = null;
    try {
        app(StartTaskVmLifecycleOperationAction::class)->execute(123, $verb);
    } catch (ResourceOperationException $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(ResourceOperationException::class);
    expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(409);
    expect(Operation::query()->count())->toBe(0);
    expect($socket->sent)->toBe([]);
})->with(['provision', 'enroll', 'resume', 'invalid']);
