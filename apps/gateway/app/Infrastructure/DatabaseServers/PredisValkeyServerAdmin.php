<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\DatabaseServer;
use Closure;
use Illuminate\Support\Sleep;
use Predis\Client;
use Throwable;

/**
 * Runs Valkey admin commands with Predis over TCP to the Node's WireGuard address and the server's port, as `default`
 * with the admin password. Each call opens one connection and closes it. Error replies and connection failures never
 * reach a log: the ACL lines and Valkey's messages stay in this class, except a refused rule's message with every
 * hash masked.
 */
final readonly class PredisValkeyServerAdmin implements ValkeyServerAdmin
{
    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    private const float READ_WRITE_TIMEOUT_SECONDS = 10.0;

    private const int READY_POLL_SECONDS = 2;

    public function __construct(private CommandDeadline $deadline) {}

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        $deadline = now()->addSeconds($timeoutSeconds);

        while (! $this->acceptsAdminLogin($server)) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                return false;
            }

            Sleep::for(self::READY_POLL_SECONDS)->seconds();
        }

        return true;
    }

    public function acceptsAdminLogin(DatabaseServer $server): bool
    {
        try {
            return $this->connected($server, true, function (Client $client): bool {
                $reply = $client->executeRaw(['PING'], $error);

                return $error === false && $reply === 'PONG';
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseCount(DatabaseServer $server): int
    {
        $reply = $this->connected($server, true, fn (Client $client): mixed => $this->command($server, $client, ['CONFIG', 'GET', 'databases']));
        $count = is_array($reply) ? ($reply[1] ?? null) : null;
        if (! is_string($count) || ! ctype_digit($count) || (int) $count < 1) {
            throw $this->failed($server);
        }

        return (int) $count;
    }

    public function adoptionConfiguration(DatabaseServer $server): array
    {
        $keys = ['dir', 'appendonly', 'appenddirname', 'appendfilename', 'dbfilename', 'databases'];
        $reply = $this->connected($server, true, fn (Client $client): mixed => $this->command($server, $client, ['CONFIG', 'GET', ...$keys]));
        if (! is_array($reply) || count($reply) !== count($keys) * 2) {
            throw $this->failed($server);
        }

        $configuration = [];
        foreach (array_chunk($reply, 2) as [$key, $value]) {
            if (! is_string($key) || ! is_string($value) || ! in_array($key, $keys, true) || array_key_exists($key, $configuration)) {
                throw $this->failed($server);
            }
            $configuration[$key] = $value;
        }

        return $configuration;
    }

    public function userExists(DatabaseServer $server, string $username): bool
    {
        return $this->connected(
            $server,
            true,
            fn (Client $client): bool => $this->command($server, $client, ['ACL', 'GETUSER', $username]) !== null,
        );
    }

    public function deleteUser(DatabaseServer $server, string $username): void
    {
        $this->connected($server, true, function (Client $client) use ($server, $username): void {
            $previous = $this->users($server, $client)[$username] ?? null;
            $this->command($server, $client, ['ACL', 'DELUSER', $username]);
            try {
                $this->command($server, $client, ['ACL', 'SAVE']);
            } catch (ResourceOperationException $exception) {
                if ($previous instanceof ValkeyAclUser) {
                    try {
                        $this->command($server, $client, self::setUserArguments($previous));
                        $this->command($server, $client, ['ACL', 'SAVE']);
                    } catch (ResourceOperationException) {
                        // The record remains available for retry and Doctor reports any live drift.
                    }
                }
                throw $exception;
            }
        });
    }

    public function aclUsers(DatabaseServer $server): array
    {
        return $this->connected($server, true, fn (Client $client): array => $this->users($server, $client));
    }

    public function aclFile(DatabaseServer $server): ?string
    {
        $reply = $this->connected(
            $server,
            true,
            fn (Client $client): mixed => $this->command($server, $client, ['CONFIG', 'GET', 'aclfile']),
        );
        $file = is_array($reply) ? ($reply[1] ?? null) : null;

        return is_string($file) && $file !== '' ? $file : null;
    }

    public function acceptsAnonymous(DatabaseServer $server): bool
    {
        return $this->connected($server, false, function (Client $client) use ($server): bool {
            $reply = $this->send($server, $client, ['PING'], $error);

            return ! ($error === true && is_string($reply) && str_starts_with($reply, 'NOAUTH'));
        });
    }

    /** @return array<string, ValkeyAclUser> */
    private function users(DatabaseServer $server, Client $client): array
    {
        $lines = $this->command($server, $client, ['ACL', 'LIST']);
        $users = [];

        foreach (is_array($lines) ? $lines : throw $this->failed($server) as $line) {
            $user = is_string($line) ? ValkeyAclUser::fromAclListLine($line) : null;

            if (! $user instanceof ValkeyAclUser) {
                throw $this->failed($server);
            }

            $users[$user->name] = $user;
        }

        return $users;
    }

    /**
     * Send one command and fail on an error reply.
     *
     * @param  list<string>  $arguments
     */
    private function command(DatabaseServer $server, Client $client, array $arguments): mixed
    {
        $reply = $this->send($server, $client, $arguments, $error);

        if ($error === true) {
            throw $this->failed($server);
        }

        return $reply;
    }

    /**
     * Send one command. A connection failure throws; an error reply sets `$error` and returns Valkey's message.
     *
     * @param  list<string>  $arguments
     */
    private function send(DatabaseServer $server, Client $client, array $arguments, ?bool &$error = null): mixed
    {
        try {
            return $client->executeRaw($arguments, $error);
        } catch (Throwable) {
            throw $this->failed($server);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(Client): T  $operation
     * @return T
     */
    private function connected(DatabaseServer $server, bool $authenticated, Closure $operation): mixed
    {
        $client = $this->client($server, $authenticated);

        try {
            return $operation($client);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // The connection is gone either way.
            }
        }
    }

    private function client(DatabaseServer $server, bool $authenticated): Client
    {
        $host = $server->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw $this->failed($server);
        }

        return new Client([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $server->port,
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_write_timeout' => self::READ_WRITE_TIMEOUT_SECONDS,
            'protocol' => 2,
            'client_info' => false,
            ...($authenticated ? ['username' => 'default', 'password' => $server->root_password] : []),
        ], [
            'connections' => new DeadlineValkeyConnectionFactory($this->deadline),
        ]);
    }

    /** @return list<string> */
    private static function setUserArguments(ValkeyAclUser $user): array
    {
        return [
            'ACL', 'SETUSER', $user->name, 'reset',
            ...explode(' ', $user->rules),
            ...array_map(static fn (string $hash): string => '#'.$hash, $user->passwordHashes),
        ];
    }

    private function failed(DatabaseServer $server): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_command_failed',
            message: "Database server [{$server->slug}] could not run an admin command.",
            status: 502,
        );
    }
}
