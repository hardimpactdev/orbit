<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;
use Throwable;

/** The Gateway owns the value; native vault.put receives its execution copy in an authenticated frame. */
final readonly class SwampVaultExecutionCopy
{
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
        $stamp = 'swamp:execution-copy:'.hash('sha256', "wss://swamp.orbit\0".$vault."\0".$key);
        $digest = hash('sha256', $value);
        if (Cache::get($stamp) === $digest) {
            return $reference;
        }
        $id = bin2hex(random_bytes(16));
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 15);
            $this->socket->send(['type' => 'vault.put', 'id' => $id,
                'payload' => ['vaultName' => $vault, 'key' => $key, 'value' => $value, 'force' => true]]);
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $payload = $message['payload'] ?? null;
                    $data = is_array($payload) ? ($payload['data'] ?? null) : null;
                    if (($message['type'] ?? null) !== 'vault.put' || ! is_array($data)
                        || ($data['vaultName'] ?? null) !== $vault || ($data['secretKey'] ?? null) !== $key
                        || ($data['vaultType'] ?? null) !== 'local_encryption') {
                        throw $this->unavailable();
                    }
                    Cache::forever($stamp, $digest);

                    return $reference;
                }
            }
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
        throw $this->unavailable();
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.vault_sync_unavailable', 'The Swamp execution copy could not be stored.', 503);
    }
}
