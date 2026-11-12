<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\Instances\StartInstanceRestoreAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\RouteExecutionProof;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Support\ValidatedData;

/** Exact surviving PostgreSQL catalog identities and this restoration's captured journal set. */
final readonly class InstanceRestoreFence
{
    public function __construct(private RemoteProgramRunner $reader) {}

    /** @param array<string,mixed> $database
     * @param  list<array<string,mixed>>  $prior
     * @return list<array<string,mixed>>
     */
    public function capture(array $database, array $prior = []): array
    {
        $captured = [];
        foreach (RouteExecutionProof::objects($database['servers'] ?? []) as $row) {
            $plan = ValidatedData::object($row['intent']);
            $server = DatabaseServer::query()->findOrFail(ValidatedData::integer($row['server_id']));
            if (($plan['driver'] ?? null) !== 'pgsql' || $server->driver !== DatabaseDriver::Pgsql) {
                StartInstanceRestoreAction::refuse('evidence_missing', 'This database plan has no PostgreSQL survivor and fence evidence.');
            }
            $names = ValidatedData::stringList($plan['databases']);
            $users = ValidatedData::stringList($plan['users']);
            $sql = $this->catalogSql($names, $users);
            $program = new RemoteProgramCatalog(base_path('../../swamp/ansible'))->program('instance.restore-source');
            $argv = DatabaseStatements::login(DatabaseDriver::Pgsql, ValidatedData::string($plan['container']), 'postgres', 'postgres');
            try {
                $read = $this->reader->run($server->node, $program, ['action' => 'catalog', 'argv' => $argv,
                    'password' => $server->root_password, 'sql' => $sql]);
            } catch (RemoteProgramFailed) {
                StartInstanceRestoreAction::refuse('evidence_missing', 'The administrator catalog read could not finish.');
            }
            if ($read->outcome !== 'intact') {
                StartInstanceRestoreAction::refuse('evidence_missing', 'The administrator could not read the planned database and fence catalog.');
            }
            $facts = $read->data;
            $databases = RouteExecutionProof::objects($facts['databases'] ?? []);
            $roles = RouteExecutionProof::objects($facts['users'] ?? []);
            $fences = RouteExecutionProof::objects($facts['fences'] ?? []);
            $seen = [];
            foreach ($databases as $current) {
                $seen[ValidatedData::string($current['name'])] = $current;
            }
            if (count($seen) !== count($names) || array_diff($names, array_keys($seen)) !== []) {
                StartInstanceRestoreAction::refuse('database_missing', 'A database in the whole planned set is gone.');
            }
            if (array_diff($users, ValidatedData::stringList(array_column($roles, 'name'))) !== []) {
                StartInstanceRestoreAction::refuse('user_missing', 'A user in the planned database removal set is gone.');
            }
            $owners = ValidatedData::object($plan['owners'] ?? []);
            $mainNames = DatabaseConnection::query()->whereKey(ValidatedData::integerList($database['connection_ids'] ?? []))
                ->where('database_server_id', $server->id)->pluck('database')->all();
            foreach ($seen as $name => $current) {
                if (($owners[$name] ?? null) !== ($current['owner'] ?? null)) {
                    StartInstanceRestoreAction::refuse('ownership_changed', "Planned database [{$name}] has a different owner.");
                }
                // Main databases must already admit clients; each other member retains its captured value.
                if (! is_bool($current['allow'] ?? null) || ! is_int($current['oid'] ?? null)
                    || (in_array($name, $mainNames, true) && ! $current['allow'])) {
                    StartInstanceRestoreAction::refuse('database_admission_changed', "Planned database [{$name}] does not admit connections.");
                }
            }
            foreach ($fences as $fence) {
                $current = $seen[ValidatedData::string($fence['name'])] ?? null;
                if ($current === null || $current['oid'] !== ($fence['oid'] ?? null)) {
                    StartInstanceRestoreAction::refuse('database_identity_changed', 'A planned fence name or database OID changed.');
                }
                if ($current['allow'] !== ($fence['allow'] ?? null)) {
                    StartInstanceRestoreAction::refuse('database_admission_changed', 'A planned fence has a different allow-connections value.');
                }
            }
            $before = array_find($prior, static fn (array $entry): bool => ($entry['server_id'] ?? null) === $server->id);
            if ($before !== null) {
                foreach (RouteExecutionProof::objects($before['databases']) as $old) {
                    $current = $seen[ValidatedData::string($old['name'])];
                    if ($current['oid'] !== $old['oid']) {
                        StartInstanceRestoreAction::refuse('database_identity_changed', 'A database identity captured by the first restore attempt changed.');
                    }
                    if ($current['owner'] !== $old['owner']) {
                        StartInstanceRestoreAction::refuse('ownership_changed', 'Database ownership changed between restore attempts.');
                    }
                    if ($current['allow'] !== $old['allow']) {
                        StartInstanceRestoreAction::refuse('database_admission_changed', 'Database admission changed between restore attempts.');
                    }
                }
                $oldUsers = RouteExecutionProof::objects($before['users']);
                foreach ($oldUsers as $old) {
                    $current = array_find($roles, static fn (array $role): bool => $role['name'] === $old['name']);
                    if ($current === null || $current['oid'] !== $old['oid']) {
                        StartInstanceRestoreAction::refuse('ownership_changed', 'A user identity captured by the first restore attempt changed.');
                    }
                }
                $originalFences = RouteExecutionProof::objects($before['fences']);
                foreach ($fences as $fence) {
                    if (! in_array($fence, $originalFences, true)) {
                        StartInstanceRestoreAction::refuse('database_admission_changed', 'The journal now contains a row outside this restoration capture.');
                    }
                }
                $fences = $originalFences;
            }
            $captured[] = ['server_id' => $server->id, 'node_id' => $server->node_id, 'container' => $plan['container'],
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($server->node),
                'admin_client' => $argv, 'databases' => array_values($seen), 'users' => $roles, 'fences' => $fences,
                'statements' => self::settlementSql(array_values($seen), $roles, $fences)];
        }

        return $captured;
    }

    /** @param list<string> $names
     * @param  list<string>  $users
     */
    public function catalogSql(array $names, array $users): string
    {
        if (count($names) > 256 || count($users) > 256 || $names === []) {
            StartInstanceRestoreAction::refuse('evidence_missing', 'The bounded database inventory is incomplete.');
        }
        $namesSql = self::literals($names);
        $usersSql = self::literals($users);

        // The journal may never have been created; to_regclass determines the second read without touching it.
        return "BEGIN READ ONLY; SET LOCAL statement_timeout = '10s';\n"
            ."SELECT json_build_object('databases', COALESCE((SELECT json_agg(json_build_object('name', datname, 'oid', oid::bigint, 'owner', pg_get_userbyid(datdba), 'allow', datallowconn)) FROM pg_database WHERE datname IN ({$namesSql})), '[]'::json), "
            ."'users', COALESCE((SELECT json_agg(json_build_object('name', rolname, 'oid', oid::bigint)) FROM pg_roles WHERE rolname IN ({$usersSql})), '[]'::json));\n"
            ."SELECT to_regclass('orbit_admin.database_removal_fences') IS NOT NULL AS journal_exists \\gset\n"
            ."\\if :journal_exists\nSELECT COALESCE(json_agg(json_build_object('name', datname, 'oid', oid::bigint, 'allow', allow_connections)), '[]'::json) FROM orbit_admin.database_removal_fences WHERE datname IN ({$namesSql});\n"
            ."\\else\nSELECT '[]';\n\\endif\nROLLBACK;\n";
    }

    /** @param list<array<string,mixed>> $databases
     * @param  list<array<string,mixed>>  $users
     * @param  list<array<string,mixed>>  $fences
     */
    public static function settlementSql(array $databases, array $users, array $fences): string
    {
        $db = self::literal(json_encode($databases, JSON_THROW_ON_ERROR));
        $roles = self::literal(json_encode($users, JSON_THROW_ON_ERROR));
        $journal = self::literal(json_encode($fences, JSON_THROW_ON_ERROR));

        return <<<SQL
            BEGIN;
            SET LOCAL statement_timeout = '20s';
            DO \$restore\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM json_to_recordset({$db}::json) AS p(name text, oid bigint, owner text, allow boolean)
                    LEFT JOIN pg_database d ON d.datname = p.name
                    WHERE d.oid IS DISTINCT FROM p.oid::oid
                ) THEN RAISE EXCEPTION 'instance.restore_database_identity_changed'; END IF;
                IF EXISTS (
                    SELECT 1 FROM json_to_recordset({$db}::json) AS p(name text, oid bigint, owner text, allow boolean)
                    JOIN pg_database d ON d.datname = p.name AND d.oid = p.oid::oid
                    WHERE pg_get_userbyid(d.datdba) IS DISTINCT FROM p.owner
                ) OR EXISTS (
                    SELECT 1 FROM json_to_recordset({$roles}::json) AS p(name text, oid bigint)
                    LEFT JOIN pg_roles r ON r.rolname = p.name WHERE r.oid IS DISTINCT FROM p.oid::oid
                ) THEN RAISE EXCEPTION 'instance.restore_ownership_changed'; END IF;
                IF EXISTS (
                    SELECT 1 FROM json_to_recordset({$db}::json) AS p(name text, oid bigint, owner text, allow boolean)
                    JOIN pg_database d ON d.datname = p.name AND d.oid = p.oid::oid
                    WHERE d.datallowconn IS DISTINCT FROM p.allow
                ) THEN RAISE EXCEPTION 'instance.restore_database_admission_changed'; END IF;
                IF to_regclass('orbit_admin.database_removal_fences') IS NULL THEN
                    RETURN;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM orbit_admin.database_removal_fences j
                    JOIN json_to_recordset({$db}::json) AS d(name text, oid bigint, owner text, allow boolean)
                        ON j.datname = d.name OR j.oid = d.oid::oid
                    LEFT JOIN json_to_recordset({$journal}::json) AS p(name text, oid bigint, allow boolean)
                        ON j.datname = p.name AND j.oid = p.oid::oid
                    WHERE p.oid IS NULL OR j.allow_connections IS DISTINCT FROM p.allow
                ) THEN RAISE EXCEPTION 'instance.restore_database_admission_changed'; END IF;
                DELETE FROM orbit_admin.database_removal_fences j
                USING json_to_recordset({$journal}::json) AS p(name text, oid bigint, allow boolean), pg_database d
                WHERE j.datname = p.name AND j.oid = p.oid::oid AND j.allow_connections = p.allow
                    AND d.datname = p.name AND d.oid = p.oid::oid AND d.datallowconn = p.allow;
            END
            \$restore\$;
            COMMIT;
            SQL;
    }

    /** @param list<string> $values */
    private static function literals(array $values): string
    {
        return $values === [] ? 'NULL' : implode(',', array_map(self::literal(...), $values));
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
