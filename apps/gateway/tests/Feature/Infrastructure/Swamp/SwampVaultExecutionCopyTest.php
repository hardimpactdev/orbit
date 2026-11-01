<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'synthetic-gateway.private', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'],
        'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    $this->copies = new SwampVaultExecutionCopy($this->socket);
});

it('pushes changed values in one authenticated native vault frame and returns only vault key names', function (): void {
    $key = 'bootstrap-identity-node-'.fake()->numberBetween(100000, 999999);
    $value = "-----BEGIN OPENSSH PRIVATE KEY-----\n".bin2hex(random_bytes(32))."\n-----END OPENSSH PRIVATE KEY-----\n";
    $reference = $this->copies->synchronize('orbit-control-inputs', $key, $value);
    expect($reference)->toBe(['vault' => 'orbit-control-inputs', 'key' => $key]);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0])->toMatchArray(['type' => 'vault.put',
        'payload' => ['vaultName' => 'orbit-control-inputs', 'key' => $key, 'value' => $value, 'force' => true]]);
    expect($this->socket->endpoint->address)->toBe('192.0.2.209');
    expect($this->socket->endpoint->serverName)->toBe('swamp.orbit');
    expect($this->socket->endpoint->caPath)->toBe('/public/root.pem');
    expect($this->socket->endpoint->bearerToken)->toBe('synthetic-gateway.private');
    expect($this->socket->closed)->toBeTrue();
    $this->copies->synchronize('orbit-control-inputs', $key, $value);
    expect($this->socket->sent)->toHaveCount(1);
    $this->copies->synchronize('orbit-control-inputs', $key, $value.'changed');
    expect($this->socket->sent)->toHaveCount(2);
    expect(Cache::get('swamp:execution-copy:'.hash('sha256', "wss://swamp.orbit\0orbit-control-inputs\0".$key)))
        ->toBe(hash('sha256', $value.'changed'));
});

it('does not mark a refused or mismatched copy synchronized and drops secret-bearing diagnostics', function (string $failure): void {
    $key = 'bootstrap-identity-node-'.fake()->numberBetween(100000, 999999);
    $value = 'synthetic-private-'.bin2hex(random_bytes(32));
    $this->socket->reply = static function (array $request) use ($failure, $value): array {
        if ($failure === 'transport') {
            throw new RuntimeException($value);
        }

        return $failure === 'forbidden'
            ? [['type' => 'error', 'id' => $request['id'], 'code' => 'forbidden', 'message' => $value]]
            : [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
                'vaultName' => $failure === 'vault' ? 'foreign' : $request['payload']['vaultName'],
                'secretKey' => $failure === 'key' ? 'foreign' : $request['payload']['key'],
                'vaultType' => $failure === 'type' ? 'foreign' : 'local_encryption',
            ]]]];
    };
    try {
        $this->copies->synchronize('orbit-control-inputs', $key, $value);
        test()->fail('A failed native write must refuse.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.vault_sync_unavailable');
        expect((string) $error)->not->toContain($value);
        expect($error->getPrevious())->toBeNull();
    }
    expect(Cache::get('swamp:execution-copy:'.hash('sha256', "wss://swamp.orbit\0orbit-control-inputs\0".$key)))->toBeNull();
    expect($this->socket->closed)->toBeTrue();
})->with(['transport', 'forbidden', 'vault', 'key', 'type']);

it('ignores another request response without marking the copy synchronized', function (): void {
    $this->socket->reply = static fn (): array => [['type' => 'vault.put', 'id' => 'foreign', 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs', 'secretKey' => 'key']]]];
    expect(fn () => $this->copies->synchronize('orbit-control-inputs', 'key', 'synthetic-value'))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toHaveCount(1);
});

it('records handshake status separately from a vault refusal without logging private diagnostics', function (string $failure): void {
    Log::spy();
    $private = 'private-value-and-remote-message';
    if ($failure === 'connect') {
        $this->socket->onConnect = static fn () => throw new WebSocketException($private, 429);
    } else {
        $this->socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'],
            'error' => ['code' => $failure === 'forbidden' ? 'forbidden' : $private, 'message' => $private]]];
    }
    try {
        $this->copies->synchronize('orbit-control-inputs', 'database-write-intent-20', $private);
        test()->fail('The failed copy must refuse.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.vault_sync_unavailable');
        expect((string) $error)->not->toContain($private);
    }
    Log::shouldHaveReceived('warning')->once()->with('Swamp execution copy unavailable', Mockery::on(function (array $context) use ($failure, $private): bool {
        expect($context)->toMatchArray(['vault' => 'orbit-control-inputs', 'key' => 'database-write-intent-20',
            'phase' => $failure === 'connect' ? 'connect' : 'acknowledge',
            'remote_code' => $failure === 'connect' ? null : ($failure === 'forbidden' ? 'forbidden' : 'unrecognized'),
            'http_status' => $failure === 'connect' ? 429 : null]);
        expect($context['vault_request_id'])->toMatch('/\A[a-f0-9]{32}\z/');
        expect(json_encode($context))->not->toContain($private, 'synthetic-gateway.private');

        return true;
    }));
    expect($this->socket->closed)->toBeTrue();
})->with(['connect', 'forbidden', 'unrecognized']);

it('refuses invalid names or missing connection configuration before opening a socket', function (string $vault, string $key, string $address): void {
    config(['swamp.address' => $address]);
    expect(fn () => $this->copies->synchronize($vault, $key, 'synthetic-value'))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBeEmpty();
    expect($this->socket->connections)->toBeEmpty();
})->with([
    ['--vault', 'key', '192.0.2.209'],
    ['orbit-control-inputs', '--key', '192.0.2.209'],
    ['orbit-control-inputs', 'key', ''],
]);

it('reads a schema-sensitive native value only from the matching authenticated vault response', function (): void {
    $this->socket->reply = static fn (array $request): array => [['type' => 'vault.read-secret', 'id' => $request['id'],
        'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => 'synthetic-output-private']]]];
    expect($this->copies->readSecret('orbit-control-inputs', 'native-output-key'))->toBe('synthetic-output-private');
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['type'])->toBe('vault.read-secret');
    expect(json_encode($this->socket->sent))->not->toContain('synthetic-output-private');
    expect($this->socket->endpoint->bearerToken)->toBe('synthetic-gateway.private');
    expect($this->socket->closed)->toBeTrue();
});

it('refuses foreign or missing native output data without retaining private diagnostics', function (string $failure): void {
    $this->socket->reply = static function (array $request) use ($failure): array {
        if ($failure === 'refused') {
            return [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'unauthorized', 'message' => 'private-native-diagnostic']]];
        }

        return [['type' => 'vault.read-secret', 'id' => $request['id'], 'payload' => ['data' => [
            'vaultName' => $failure === 'vault' ? 'other' : $request['payload']['vaultName'],
            'secretKey' => $failure === 'key' ? 'other' : $request['payload']['secretKey'],
            'vaultType' => $failure === 'type' ? 'other' : 'local_encryption', 'value' => $failure === 'value' ? null : 'private-native-diagnostic',
        ]]]];
    };
    try {
        $this->copies->readSecret('orbit-control-inputs', 'native-output-key');
        test()->fail('A mismatched output must refuse.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.secret_output_unavailable');
        expect((string) $error)->not->toContain('private-native-diagnostic');
        expect($error->getPrevious())->toBeNull();
    }
    expect($this->socket->closed)->toBeTrue();
})->with(['refused', 'vault', 'key', 'type', 'value']);
