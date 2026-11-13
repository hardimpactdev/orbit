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
    private const int ConnectSeconds = 15;

    public function __construct(private WebSocketClient $socket) {}

    /** @return array{vault:string,key:string} */
    public function synchronize(string $vault, string $key, #[SensitiveParameter] string $value): array
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
        $reference = ['vault' => $vault, 'key' => $key];
        $stamp = self::stamp($vault, $key);
        $digest = hash('sha256', $value);
        if (Cache::get($stamp) !== $digest) {
            $endpoint = new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token);
            app(SwampDispatch::class)->copy(fn () => $this->push($endpoint, $vault, $key, $value, $stamp, $digest));
        }

        return $reference;
    }

    /** Forget the pushed digest of a key that was deleted on serve, so a later copy pushes it again. */
    public static function forget(string $vault, string $key): void
    {
        Cache::forget(self::stamp($vault, $key));
    }

    private static function stamp(string $vault, string $key): string
    {
        return 'swamp:execution-copy:'.hash('sha256', "wss://swamp.orbit\0".$vault."\0".$key);
    }

    /** One native vault.put. It never runs under the SQLite write lock; SwampDispatch defers it past commit. */
    private function push(WebSocketEndpoint $endpoint, string $vault, string $key, #[SensitiveParameter] string $value, string $stamp, string $digest): void
    {
        $id = bin2hex(random_bytes(16));
        $phase = 'connect';
        $remoteCode = null;
        $dispatch = app(SwampDispatch::class);
        $shared = false;
        $socket = $this->socket;
        try {
            [$socket, $shared] = $this->connect($dispatch, $endpoint, $vault, $key, $id);
            $phase = 'send';
            $socket->send(['type' => 'vault.put', 'id' => $id,
                'payload' => ['vaultName' => $vault, 'key' => $key, 'value' => $value, 'force' => true]]);
            $phase = 'receive';
            $deadline = microtime(true) + 30;
            while ($socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $phase = 'acknowledge';
                    if (($message['type'] ?? null) === 'error') {
                        $error = is_array($message['error'] ?? null) ? $message['error'] : $message;
                        $code = $error['code'] ?? null;
                        $remoteCode = in_array($code, ['forbidden', 'unauthorized', 'not_found', 'vault_put_failed', 'cancelled', 'bad_request', 'internal_error'], true)
                            ? $code : 'unrecognized';
                        throw $this->unavailable();
                    }
                    $payload = $message['payload'] ?? null;
                    $data = is_array($payload) ? ($payload['data'] ?? null) : null;
                    if (($message['type'] ?? null) !== 'vault.put' || ! is_array($data)
                        || ($data['vaultName'] ?? null) !== $vault || ($data['secretKey'] ?? null) !== $key
                        || ($data['vaultType'] ?? null) !== 'local_encryption') {
                        throw $this->unavailable();
                    }
                    $phase = 'cache';
                    Cache::forever($stamp, $digest);

                    return;
                }
            }
            throw $this->unavailable();
        } catch (Throwable $exception) {
            if ($shared) {
                $dispatch->release();
            }
            Log::warning('Swamp execution copy unavailable', ['vault' => $vault, 'key' => $key, 'vault_request_id' => $id,
                'phase' => $phase, 'remote_code' => $remoteCode, 'exception_type' => $exception::class,
                'http_status' => $phase === 'connect' ? self::httpStatus($exception) : null]);
            // A failed connect keeps its cause: no frame was sent, so a workflow start that needed this copy may be queued again.
            throw $this->unavailable($phase === 'connect' ? $exception : null);
        } finally {
            if (! $shared) {
                $socket->close();
            }
        }
    }

    /**
     * Connects for one vault.put, with one more try when the first connect gets no HTTP answer. vault.put with `force` stores the same
     * key and value, so a second try is safe, and no request was sent before it. A refusal with an HTTP status is not tried again.
     * Two tries cover a gap of up to about 30 seconds; a longer serve stall still fails the copy.
     *
     * @return array{0: WebSocketClient, 1: bool}
     */
    private function connect(SwampDispatch $dispatch, WebSocketEndpoint $endpoint, string $vault, string $key, string $id): array
    {
        try {
            return $dispatch->socket($endpoint, self::ConnectSeconds, $this->socket);
        } catch (Throwable $exception) {
            if (self::httpStatus($exception) !== null) {
                throw $exception;
            }
            Log::info('Swamp execution copy connect retried', ['vault' => $vault, 'key' => $key, 'vault_request_id' => $id,
                'exception_type' => $exception::class]);

            return $dispatch->socket($endpoint, self::ConnectSeconds, $this->socket);
        }
    }

    private static function httpStatus(Throwable $exception): ?int
    {
        return $exception instanceof WebSocketException && $exception->getCode() >= 400 && $exception->getCode() <= 599
            ? $exception->getCode() : null;
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

    private function unavailable(?Throwable $connectFailure = null): ResourceOperationException
    {
        return new ResourceOperationException('swamp.vault_sync_unavailable', 'The Swamp execution copy could not be stored.', 503, $connectFailure);
    }
}
