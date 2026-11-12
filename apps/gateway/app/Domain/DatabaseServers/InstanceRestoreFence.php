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
            $owners = ValidatedData::object($plan['owners'] ?? []);
            $users = array_values(array_unique([...ValidatedData::stringList($plan['users']), ...ValidatedData::stringList(array_values($owners))]));
            app(DatabaseDestroySafety::class)->assertAcceptsRestore($server, $names, $users);
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
            $missing = array_values(array_diff($names, array_keys($seen)));
            if ($missing !== [] || count($seen) !== count($names)) {
                StartInstanceRestoreAction::refuse('database_missing', 'Planned databases are gone: '.implode(', ', $missing), ['databases' => $missing]);
            }
            $missingUsers = array_values(array_diff($users, ValidatedData::stringList(array_column($roles, 'name'))));
            if ($missingUsers !== []) {
                StartInstanceRestoreAction::refuse('user_missing', 'Planned database users are gone: '.implode(', ', $missingUsers),
                    ['databases' => $names, 'users' => $missingUsers]);
            }
            $mainNames = DatabaseConnection::query()->whereKey(ValidatedData::integerList($database['connection_ids'] ?? []))
                ->where('database_server_id', $server->id)->pluck('database')->all();
            $before = array_find($prior, static fn (array $entry): bool => ($entry['server_id'] ?? null) === $server->id);
            $originalFences = $before === null ? $fences : RouteExecutionProof::objects($before['fences']);
            foreach ($fences as $fence) {
                $name = ValidatedData::string($fence['name']);
                $current = $seen[$name] ?? null;
                if ($current === null || $current['oid'] !== ($fence['oid'] ?? null) || ! is_bool($fence['allow'] ?? null)) {
                    StartInstanceRestoreAction::refuse('database_identity_changed', "The journal identity changed for database [{$name}].", ['databases' => [$name]]);
                }
                if (! in_array($fence, $originalFences, true)) {
                    StartInstanceRestoreAction::refuse('database_admission_changed', "The journal changed for database [{$name}].", ['databases' => [$name]]);
                }
            }
            foreach ($seen as $name => &$current) {
                if (($owners[$name] ?? null) !== ($current['owner'] ?? null)) {
                    StartInstanceRestoreAction::refuse('ownership_changed', "Planned database [{$name}] has a different owner.", ['databases' => [$name]]);
                }
                if (! is_bool($current['allow'] ?? null) || ! is_int($current['oid'] ?? null)) {
                    StartInstanceRestoreAction::refuse('evidence_missing', "Database [{$name}] has incomplete catalog evidence.", ['databases' => [$name]]);
                }
                $journal = array_find($fences, static fn (array $row): bool => $row['name'] === $name);
                $original = array_find($originalFences, static fn (array $row): bool => $row['name'] === $name);
                $old = $before === null ? null : array_find(RouteExecutionProof::objects($before['databases']), static fn (array $row): bool => $row['name'] === $name);
                if ($old !== null && ($current['oid'] !== $old['oid'] || $current['owner'] !== $old['owner'])) {
                    StartInstanceRestoreAction::refuse('database_identity_changed', "Database [{$name}] changed identity between restore attempts.", ['databases' => [$name]]);
                }
                $desired = $original['allow'] ?? $old['allow'] ?? $current['allow'];
                // A present matching journal permits the fence's closed state or its original value.
                // Without that journal, admission must already equal the captured destination.
                if (($journal === null && ($current['allow'] !== $desired || ($before === null && ! $current['allow'])))
                    || ($journal !== null && $current['allow'] && ! $desired)
                    || (in_array($name, $mainNames, true) && ! $desired)) {
                    StartInstanceRestoreAction::refuse('database_admission_changed', "Database [{$name}] has no matching fence that can restore its admission.", ['databases' => [$name]]);
                }
                $current['allow'] = $desired;
            }
            unset($current);
            if ($before !== null) {
                foreach (RouteExecutionProof::objects($before['users']) as $old) {
                    $current = array_find($roles, static fn (array $role): bool => $role['name'] === $old['name']);
                    if ($current === null || $current['oid'] !== $old['oid']) {
                        StartInstanceRestoreAction::refuse('ownership_changed', 'A captured database user changed identity.',
                            ['databases' => $names, 'users' => [$old['name']]]);
                    }
                }
            }
            $fences = $originalFences;
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
            ."\\if :journal_exists\nSELECT COALESCE(json_agg(json_build_object('name', datname, 'oid', oid::bigint, 'allow', allow_connections)), '[]'::json) FROM orbit_admin.database_removal_fences WHERE datname IN ({$namesSql}) OR oid IN (SELECT oid FROM pg_database WHERE datname IN ({$namesSql}));\n"
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

        // PostgreSQL 18 permits ALLOW_CONNECTIONS inside this transaction (only SET TABLESPACE forbids it).
        // Catalog locks keep identity/ownership stable across validation, ALTER and the exact journal DELETE.
        return <<<SQL
            BEGIN;
            SET LOCAL statement_timeout = '20s';
            LOCK TABLE pg_catalog.pg_database IN SHARE ROW EXCLUSIVE MODE;
            LOCK TABLE pg_catalog.pg_authid IN SHARE MODE;
            DO \$restore\$
            DECLARE
                changed text[];
                live_journal json := '[]'::json;
                entry record;
                removed integer;
            BEGIN
                SELECT array_agg(p.name ORDER BY p.name) INTO changed
                FROM json_to_recordset({$db}::json) AS p(name text, oid bigint, owner text, allow boolean)
                LEFT JOIN pg_database d ON d.datname = p.name
                WHERE d.oid IS DISTINCT FROM p.oid::oid OR pg_get_userbyid(d.datdba) IS DISTINCT FROM p.owner;
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'instance.restore_database_changed'
                        USING DETAIL = json_build_object('reason', 'database_identity_changed', 'databases', changed)::text;
                END IF;
                SELECT array_agg(p.name ORDER BY p.name) INTO changed
                FROM json_to_recordset({$roles}::json) AS p(name text, oid bigint)
                LEFT JOIN pg_roles r ON r.rolname = p.name WHERE r.oid IS DISTINCT FROM p.oid::oid;
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'instance.restore_database_changed'
                        USING DETAIL = json_build_object('reason', 'ownership_changed', 'users', changed,
                            'databases', (SELECT json_agg(p.name) FROM json_to_recordset({$db}::json) AS p(name text)))::text;
                END IF;
                SELECT array_agg(j.name ORDER BY j.name) INTO changed
                FROM json_to_recordset({$journal}::json) AS j(name text, oid bigint, allow boolean)
                LEFT JOIN json_to_recordset({$db}::json) AS p(name text, oid bigint)
                    ON j.name = p.name AND j.oid = p.oid
                WHERE p.oid IS NULL OR j.allow IS NULL;
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'instance.restore_database_changed'
                        USING DETAIL = json_build_object('reason', 'journal_changed', 'databases', changed)::text;
                END IF;
                IF to_regclass('orbit_admin.database_removal_fences') IS NOT NULL THEN
                    LOCK TABLE orbit_admin.database_removal_fences IN SHARE ROW EXCLUSIVE MODE;
                    SELECT COALESCE(json_agg(json_build_object('name', j.datname, 'oid', j.oid::bigint, 'allow', j.allow_connections)), '[]'::json)
                    INTO live_journal FROM orbit_admin.database_removal_fences j
                    JOIN json_to_recordset({$db}::json) AS p(name text, oid bigint)
                        ON j.datname = p.name OR j.oid = p.oid::oid;
                END IF;
                SELECT array_agg(j.name ORDER BY j.name) INTO changed
                FROM json_to_recordset(live_journal) AS j(name text, oid bigint, allow boolean)
                LEFT JOIN json_to_recordset({$journal}::json) AS p(name text, oid bigint, allow boolean)
                    ON j.name = p.name AND j.oid = p.oid
                WHERE p.oid IS NULL OR j.allow IS DISTINCT FROM p.allow;
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'instance.restore_database_changed'
                        USING DETAIL = json_build_object('reason', 'journal_changed', 'databases', changed)::text;
                END IF;
                SELECT array_agg(p.name ORDER BY p.name) INTO changed
                FROM json_to_recordset({$db}::json) AS p(name text, oid bigint, owner text, allow boolean)
                JOIN pg_database d ON d.datname = p.name AND d.oid = p.oid::oid
                LEFT JOIN json_to_recordset({$journal}::json) AS f(name text, oid bigint, allow boolean)
                    ON f.name = p.name AND f.oid = p.oid
                LEFT JOIN json_to_recordset(live_journal) AS j(name text, oid bigint, allow boolean)
                    ON j.name = p.name AND j.oid = p.oid
                WHERE (j.oid IS NULL AND d.datallowconn IS DISTINCT FROM COALESCE(f.allow, p.allow))
                    OR (j.oid IS NOT NULL AND d.datallowconn AND NOT j.allow);
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'instance.restore_database_changed'
                        USING DETAIL = json_build_object('reason', 'database_admission_changed', 'databases', changed)::text;
                END IF;
                FOR entry IN
                    SELECT j.name, j.allow, d.datallowconn AS current_allow
                    FROM json_to_recordset(live_journal) AS j(name text, oid bigint, allow boolean)
                    JOIN pg_database d ON d.datname = j.name AND d.oid = j.oid::oid ORDER BY j.name
                LOOP
                    IF entry.current_allow IS DISTINCT FROM entry.allow THEN
                        EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', entry.name, CASE WHEN entry.allow THEN 'true' ELSE 'false' END);
                    END IF;
                END LOOP;
                IF to_regclass('orbit_admin.database_removal_fences') IS NOT NULL THEN
                    DELETE FROM orbit_admin.database_removal_fences j
                    USING json_to_recordset({$journal}::json) AS p(name text, oid bigint, allow boolean), pg_database d
                    WHERE j.datname = p.name AND j.oid = p.oid::oid AND j.allow_connections = p.allow
                        AND d.datname = p.name AND d.oid = p.oid::oid AND d.datallowconn = p.allow;
                    GET DIAGNOSTICS removed = ROW_COUNT;
                    IF removed <> json_array_length(live_journal) THEN
                        RAISE EXCEPTION 'instance.restore_database_changed'
                            USING DETAIL = json_build_object('reason', 'journal_changed',
                                'databases', (SELECT json_agg(p.name) FROM json_to_recordset({$db}::json) AS p(name text)))::text;
                    END IF;
                END IF;
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
