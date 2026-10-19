<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Models\DatabaseConnection;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Predis\Client;
use Predis\ClientInterface;
use RuntimeException;
use Throwable;

final readonly class ValkeyAiRouterCache implements AiRouterCache
{
    private ClientInterface $client;

    public function __construct(DatabaseConnection $connection, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'scheme' => 'tcp',
            'host' => $connection->host ?: '127.0.0.1',
            'port' => $connection->port ?? 6379,
            'username' => $connection->username ?: null,
            'password' => $connection->password ?: null,
            'timeout' => 3,
            'read_write_timeout' => 3,
        ]);
    }

    public function get(string $key): ?string
    {
        $reply = $this->command(['GET', $key]);

        return is_string($reply) ? $reply : null;
    }

    public function renameCollectorKeys(string $from, string $to): void
    {
        // The retired collector has stopped. RENAME keeps TTLs; a retry finishes a partial rename.
        $cursor = '0';
        do {
            $page = $this->command(['SCAN', $cursor, 'MATCH', $from.'*', 'COUNT', '100']);
            if (! is_array($page) || count($page) !== 2 || ! is_array($page[1])) {
                throw new RuntimeException('Could not scan the collector cache.');
            }
            $cursor = (string) $page[0];
            foreach ($page[1] as $key) {
                if (! is_string($key) || ! str_starts_with($key, $from)) {
                    throw new RuntimeException('Could not scan the collector cache.');
                }
                $suffix = substr($key, strlen($from));
                if ($suffix === 'lock') {
                    $this->command(['DEL', $key]);
                } elseif ($this->command(['EXISTS', $key]) === 1) {
                    $this->command(['RENAME', $key, $to.$suffix]);
                }
            }
        } while ($cursor !== '0');
    }

    public function put(string $key, string $value, ?int $seconds = null): void
    {
        $seconds === null
            ? $this->command(['SET', $key, $value])
            : $this->command(['SET', $key, $value, 'EX', (string) $seconds]);
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): mixed
    {
        try {
            $reply = $this->client->executeRaw($arguments, $error);
        } catch (Throwable) {
            throw new RuntimeException('Could not reach shared Valkey.');
        }
        if ($error) {
            throw new RuntimeException('Valkey command failed.');
        }

        return $reply;
    }
}
