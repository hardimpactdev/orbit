<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseServer;
use App\Support\ValidatedData;
use Throwable;

/**
 * Removal admission refuses while a replication slot uses a PostgreSQL database that the removal would drop
 * (docs/reference/instance-removal.md#replication-slots). The drop would close the database and then fail on the slot.
 * One bounded read per server, with the drop's own admin client on the server's Node, before any removal effect.
 */
final readonly class InstanceDestroyReplicationSlots
{
    /** Connect and read together stay within this bound; the read is part of the request. */
    public const int ReadSeconds = 5;

    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $hosts) {}

    /** @param array<string,mixed> $input The prepared removal intent. */
    public function assertNone(array $input): void
    {
        $database = ValidatedData::object(ValidatedData::object($input['direct_destroy'] ?? [])['database'] ?? []);
        $slots = [];
        foreach (is_array($database['servers'] ?? null) ? $database['servers'] : [] as $row) {
            $row = ValidatedData::object($row);
            $intent = ValidatedData::object($row['intent'] ?? []);
            $names = ValidatedData::stringList($intent['databases'] ?? []);
            if (($intent['driver'] ?? null) !== DatabaseDriver::Pgsql->value || $names === []) {
                continue;
            }
            $id = ValidatedData::integer($row['server_id']);
            $server = DatabaseServer::query()->with('node')->find($id) ?? $this->unreadable($id);
            $slots = [...$slots, ...$this->read($server, ValidatedData::nullableString($intent['container'] ?? null), $names)];
        }
        if ($slots === []) {
            return;
        }
        $first = $slots[0];
        throw new ResourceOperationException('instance.database_replication_slot',
            "Database [{$first['database']}] on Database server [{$first['server']}] has replication slot [{$first['slot']}]. "
            .'Drop the slot by hand first; Orbit never drops a replication slot.', 409, details: ['slots' => $slots]);
    }

    /**
     * @param  list<string>  $names
     * @return list<array{server_id:int,server:string,database:string,slot:string,type:string,active:bool}>
     */
    private function read(DatabaseServer $server, ?string $container, array $names): array
    {
        $statements = app(DatabaseStatements::class)->replicationSlots($names);
        $node = $server->node()->first();
        if ($node === null || $container === null || $container === '' || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            $this->unreadable($server->id, $server->slug);
        }
        try {
            // The drop's admin client, as the drop runs it (`sudo: true`); the password is the first protected stdin line.
            $result = $this->ssh->execute(new SshConnection(
                host: $node->wireguard_ip, user: $node->user, port: 22,
                identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->hosts->path(),
                connectTimeout: 2, commandTimeout: self::ReadSeconds, shareConnection: false,
            ), new RemoteCommand(
                ['timeout', (string) (self::ReadSeconds - 1), 'sudo', '-n', ...DatabaseStatements::login(DatabaseDriver::Pgsql, $container, 'postgres', 'postgres')],
                protectedInput: ProtectedInput::fromString($server->root_password."\n".$statements),
                maxOutputBytes: 65536, timeout: self::ReadSeconds,
            ));
        } catch (Throwable) {
            $this->unreadable($server->id, $server->slug);
        }
        $lines = preg_split('/\R/', trim($result->stdout)) ?: [];
        if (! $result->succeeded() || $result->truncated || array_shift($lines) !== 'orbit.slots') {
            $this->unreadable($server->id, $server->slug);
        }
        $slots = [];
        foreach ($lines as $line) {
            if (preg_match('/\A([^|]+)\|([^|]+)\|(logical|physical)\|(true|false)\z/D', $line, $match) !== 1 || ! in_array($match[2], $names, true)) {
                $this->unreadable($server->id, $server->slug);
            }
            $slots[] = ['server_id' => $server->id, 'server' => $server->slug, 'database' => $match[2], 'slot' => $match[1],
                'type' => $match[3], 'active' => $match[4] === 'true'];
        }

        return $slots;
    }

    private function unreadable(int $id, ?string $slug = null): never
    {
        $name = $slug ?? "#{$id}";
        throw new ResourceOperationException('instance.database_slots_unreadable',
            "Orbit could not read the replication slots on Database server [{$name}], so it does not close or drop its databases. Try again when the server answers.",
            503, details: ['server_id' => $id]);
    }
}
