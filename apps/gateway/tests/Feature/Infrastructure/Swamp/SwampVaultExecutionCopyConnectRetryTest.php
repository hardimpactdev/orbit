<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
});

/**
 * A socket whose connects fail with the given exceptions in turn, then succeed, and that acknowledges each vault.put.
 *
 * @param  list<Throwable>  $connectFailures
 * @param  array{connects: int, puts: int}|null  $count
 */
function vaultCopySocket(array $connectFailures, ?array &$count, bool $answer = true): WebSocketClient
{
    $count = ['connects' => 0, 'puts' => 0];
    $last = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->andReturnUsing(function () use (&$connectFailures, &$count): void {
        $count['connects']++;
        if ($connectFailures !== []) {
            throw array_shift($connectFailures);
        }
    });
    $socket->shouldReceive('close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$last, &$count): void {
        $count['puts']++;
        $last = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$last, $answer): array {
        if (! $answer) {
            throw new WebSocketException('The connection closed.');
        }

        return [['type' => 'vault.put', 'id' => $last['id'], 'payload' => ['data' => [
            'vaultName' => $last['payload']['vaultName'], 'secretKey' => $last['payload']['key'], 'vaultType' => 'local_encryption',
        ]]]];
    });
    app()->instance(WebSocketClient::class, $socket);

    return $socket;
}

it('stores the copy when only the first connect gets no answer', function (): void {
    Log::spy();
    vaultCopySocket([new WebSocketException('Connection timed out.')], $count);

    $reference = app(SwampVaultExecutionCopy::class)->synchronize('orbit-control-inputs', 'database-create-intent-759', '{"a":1}');

    expect($reference)->toBe(['vault' => 'orbit-control-inputs', 'key' => 'database-create-intent-759'])
        ->and($count)->toBe(['connects' => 2, 'puts' => 1]);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message): bool => $message === 'Swamp execution copy connect retried');
    Log::shouldNotHaveReceived('warning');
});

it('fails the copy after two connects without an answer and tries no third time', function (): void {
    vaultCopySocket([new WebSocketException('Connection timed out.'), new WebSocketException('Connection timed out.')], $count);

    expect(fn () => app(SwampVaultExecutionCopy::class)->synchronize('orbit-control-inputs', 'database-create-intent-760', '{"a":1}'))
        ->toThrow(ResourceOperationException::class, 'The Swamp execution copy could not be stored.')
        ->and($count)->toBe(['connects' => 2, 'puts' => 0]);
});

it('does not connect again after serve refused the connection with an HTTP status', function (): void {
    vaultCopySocket([new WebSocketException('Too many requests.', 429)], $count);

    expect(fn () => app(SwampVaultExecutionCopy::class)->synchronize('orbit-control-inputs', 'database-create-intent-761', '{"a":1}'))
        ->toThrow(ResourceOperationException::class)
        ->and($count)->toBe(['connects' => 1, 'puts' => 0]);
});

it('does not send the copy again after the request went out', function (): void {
    vaultCopySocket([], $count, answer: false);

    expect(fn () => app(SwampVaultExecutionCopy::class)->synchronize('orbit-control-inputs', 'database-create-intent-762', '{"a":1}'))
        ->toThrow(ResourceOperationException::class)
        ->and($count)->toBe(['connects' => 1, 'puts' => 1]);
});
