<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/** The Gateway owns the value; native vault.put receives its execution copy in an authenticated frame. */
final readonly class SwampVaultExecutionCopy
{
    public function __construct(private WebSocketClient $socket) {}

    /** @return array{vault:string,key:string} */
    public function synchronize(string $vault, string $key, #[SensitiveParameter] string $value): array
    {
        $endpoint = $this->endpoint($vault, $key);
        $stamp = $this->stamp($vault, $key);
        $digest = hash('sha256', $value);
        if (Cache::get($stamp) !== $digest) {
            $this->exchange($endpoint, 'vault.put', $vault, $key, ['vaultName' => $vault, 'key' => $key, 'value' => $value, 'force' => true]);
            Cache::forever($stamp, $digest);
        }

        return ['vault' => $vault, 'key' => $key];
    }

    /** Delete an execution copy whose owner is gone. A missing key is already removed (native force). */
    public function remove(string $vault, string $key): void
    {
        $endpoint = $this->endpoint($vault, $key);
        $this->exchange($endpoint, 'vault.delete', $vault, $key, ['vaultName' => $vault, 'key' => $key, 'force' => true]);
        Cache::forget($this->stamp($vault, $key));
    }

    private function endpoint(string $vault, string $key): WebSocketEndpoint
    {
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $vault) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,255}\z/D', $key) !== 1
            || ! is_string($address) || trim($address) === ''
            || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->unavailable();
        }

        return new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token);
    }

    private function stamp(string $vault, string $key): string
    {
        return 'swamp:execution-copy:'.hash('sha256', "wss://swamp.orbit\0".$vault."\0".$key);
    }

    /** @param  array<string,mixed>  $payload */
    private function exchange(WebSocketEndpoint $endpoint, string $type, string $vault, string $key, #[SensitiveParameter] array $payload): void
    {
        $id = bin2hex(random_bytes(16));
        $phase = 'connect';
        $remoteCode = null;
        try {
            $this->socket->connect($endpoint, 15);
            $phase = 'send';
            $this->socket->send(['type' => $type, 'id' => $id, 'payload' => $payload]);
            $phase = 'receive';
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $phase = 'acknowledge';
                    if (($message['type'] ?? null) === 'error') {
                        $error = is_array($message['error'] ?? null) ? $message['error'] : $message;
                        $code = $error['code'] ?? null;
                        $remoteCode = in_array($code, ['forbidden', 'unauthorized', 'not_found', 'vault_put_failed', 'vault_delete_failed', 'cancelled', 'bad_request', 'internal_error'], true)
                            ? $code : 'unrecognized';
                        throw $this->unavailable();
                    }
                    $data = is_array($message['payload'] ?? null) ? ($message['payload']['data'] ?? null) : null;
                    if (($message['type'] ?? null) !== $type || ! is_array($data)
                        || ($data['vaultName'] ?? null) !== $vault || ($data['secretKey'] ?? null) !== $key
                        || ($data['vaultType'] ?? null) !== 'local_encryption') {
                        throw $this->unavailable();
                    }

                    return;
                }
            }
            throw $this->unavailable();
        } catch (Throwable $exception) {
            Log::warning('Swamp execution copy unavailable', ['vault' => $vault, 'key' => $key, 'vault_request_id' => $id,
                'phase' => $phase, 'remote_code' => $remoteCode, 'exception_type' => $exception::class,
                'http_status' => $phase === 'connect' && $exception instanceof WebSocketException
                    && $exception->getCode() >= 400 && $exception->getCode() <= 599 ? $exception->getCode() : null]);
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
    }

    /** Read a native schema-sensitive output without putting the value in diagnostics or state. */
    public function readSecret(string $vault, string $key): string
    {
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $vault) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,255}\z/D', $key) !== 1
            || ! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->outputUnavailable();
        }
        $id = bin2hex(random_bytes(16));
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 15);
            $this->socket->send(['type' => 'vault.read-secret', 'id' => $id, 'payload' => ['vaultName' => $vault, 'secretKey' => $key]]);
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $payload = $message['payload'] ?? null;
                    $data = is_array($payload) ? ($payload['data'] ?? null) : null;
                    if (($message['type'] ?? null) !== 'vault.read-secret' || ! is_array($data)
                        || ($data['vaultName'] ?? null) !== $vault || ($data['secretKey'] ?? null) !== $key
                        || ($data['vaultType'] ?? null) !== 'local_encryption' || ! is_string($data['value'] ?? null)) {
                        throw $this->outputUnavailable();
                    }

                    return $data['value'];
                }
            }
            throw $this->outputUnavailable();
        } catch (Throwable) {
            throw $this->outputUnavailable();
        } finally {
            $this->socket->close();
        }
    }

    private function outputUnavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.secret_output_unavailable', 'The native secret output could not be read.', 503);
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.vault_sync_unavailable', 'The Swamp execution copy could not be stored.', 503);
    }
}
