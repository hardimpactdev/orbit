<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Actions\Doctor\DatabaseServerDoctorProbe;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/PostgresServer.php';
require_once __DIR__.'/../../../Support/ServerDatabaseFixtures.php';

uses(ServerDatabaseFixtures::class);

beforeEach(fn () => $this->prepareServerDatabaseFixtures());

describe('PostgreSQL test pool repair', function (): void {
    it('detects missing members and repairs them without changing surviving databases', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            server_database_live_postgres($this, $psql);
            $connection = DatabaseConnection::query()->where('slug', 'app')->sole();
            $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
            $psql("\\connect app\nSET ROLE dlf_feature_x; CREATE TABLE application_data (id int); INSERT INTO application_data VALUES (42);\n\\connect app_test_test_2\nSET ROLE dlf_feature_x; CREATE TABLE survivor (id int); INSERT INTO survivor VALUES (17);\n");
            $before = $psql("SELECT datname || ':' || oid FROM pg_database WHERE datname IN ('app', 'app_test_test_2') ORDER BY datname;");
            $psql('DROP DATABASE app_test; DROP DATABASE app_test_test_1;');
            $doctor = app(DatabaseServerDoctorProbe::class);
            $context = new DoctorNodeContext($this->dbNode, new NodeInspectionData(true, 'linux', 'amd64', true));
            $report = $doctor->inspect($context);
            expect(array_column($report->issues, 'code'))->toBe(['database_server.test_database_missing', 'database_server.test_database_missing']);
            expect(array_column($report->issues, 'observed'))->toBe(['app_test', 'app_test_test_1']);

            $this->postJson('/api/v1/database-connections', $request)->assertOk()->assertJsonPath('data.id', $connection->id);
            $state = $psql("SELECT datname || ':' || oid FROM pg_database WHERE datname IN ('app', 'app_test_test_2') ORDER BY datname;\n\\connect app\nSELECT id FROM application_data;\n\\connect app_test_test_2\nSELECT id FROM survivor;");
            expect($state)->toBe($before."\n42\n17");
            expect($doctor->inspect($context)->issues)->toBe([]);
            expect($psql("SELECT datname || ':' || pg_get_userbyid(datdba) FROM pg_database WHERE datname IN ('app_test', 'app_test_test_1') ORDER BY datname;"))->toBe("app_test:dlf_feature_x\napp_test_test_1:dlf_feature_x");
            expect($psql("\\connect app_test_test_1\nSELECT has_schema_privilege('dlf_feature_x', 'public', 'USAGE, CREATE')::text;\n\\connect app_test\nSELECT has_schema_privilege('dlf_feature_x', 'public', 'USAGE, CREATE')::text; SELECT rolcreatedb::text FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe("true\ntrue\nfalse");
            $this->assertDatabaseCount('database_connections', 1);
            $this->assertDatabaseCount('database_users', 1);
        });
    });

    it('keeps partial repairs for retry and leaves a missing primary database missing', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql);
            $psql('DROP DATABASE app; DROP DATABASE app_test; DROP DATABASE app_test_test_1;');
            $fail = true;
            $admin = Mockery::mock(DatabaseServerAdmin::class);
            $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($psql, &$fail): array {
                if ($fail && str_contains($sql, 'CREATE DATABASE "app_test_test_1"')) {
                    $fail = false;
                    throw new ResourceOperationException('database.server_command_failed', 'Simulated transport failure.', 502);
                }
                $output = $psql($sql);

                return $output === '' ? [] : explode("\n", $output);
            });
            app()->instance(DatabaseServerAdmin::class, $admin);
            $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
            $this->postJson('/api/v1/database-connections', $request)->assertStatus(502)->assertJsonPath('error.code', 'database.server_command_failed');
            expect($psql("SELECT datname FROM pg_database WHERE datname IN ('app_test', 'app_test_test_1') ORDER BY datname;"))->toBe('app_test');
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            $this->postJson('/api/v1/database-connections', $request)->assertOk();
            expect($psql("SELECT datname FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;"))->toBe("app_test\napp_test_test_1\napp_test_test_2");
        });
    });

    it('converges every member after repair is interrupted between CREATE and grants', function (string $member): void {
        postgres_statements_server(function (Closure $psql) use ($member): void {
            server_database_live_postgres($this, $psql);
            $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
            $psql("\\connect app\nCREATE TABLE primary_data (id int); INSERT INTO primary_data VALUES (42);\n\\connect app_test_test_2\nSET ROLE dlf_feature_x; CREATE TABLE survivor (id int); INSERT INTO survivor VALUES (17);\nRESET ROLE; GRANT CREATE ON SCHEMA public TO PUBLIC; REVOKE ALL ON SCHEMA public FROM dlf_feature_x;\n\\connect postgres\nGRANT CONNECT ON DATABASE app_test_test_2 TO PUBLIC;\nDROP DATABASE {$member};");
            $primary = $psql("SELECT oid::text || ':' || datacl::text FROM pg_database WHERE datname = 'app';");
            $interrupted = true;
            $admin = Mockery::mock(DatabaseServerAdmin::class);
            $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($psql, $member, &$interrupted): array {
                if ($interrupted && str_starts_with($sql, 'CREATE DATABASE "'.$member.'"')) {
                    $psql(explode("\n", $sql)[0]);
                    $interrupted = false;
                    throw new ResourceOperationException('database.server_command_failed', 'Interrupted before managed grants.', 502);
                }
                $output = $psql($sql);

                return $output === '' ? [] : explode("\n", $output);
            });
            app()->instance(DatabaseServerAdmin::class, $admin);
            $publicConnect = "SELECT count(*) FROM pg_database, LATERAL aclexplode(COALESCE(datacl, acldefault('d', datdba))) acl WHERE datname = '{$member}' AND acl.grantee = 0 AND acl.privilege_type = 'CONNECT';";

            $this->postJson('/api/v1/database-connections', $request)->assertStatus(502);
            expect($psql($publicConnect))->toBe('1');
            $identities = $psql("SELECT datname || ':' || oid FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;");

            $state = "SELECT datname || ':' || oid FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;\n"
                ."SELECT oid::text || ':' || datacl::text FROM pg_database WHERE datname = 'app';\n"
                ."SELECT count(*) FROM pg_database, LATERAL aclexplode(COALESCE(datacl, acldefault('d', datdba))) acl WHERE datname IN ('app_test', 'app_test_test_1', 'app_test_test_2') AND acl.grantee = 0 AND acl.privilege_type = 'CONNECT';\n"
                ."SELECT count(*) FROM pg_database WHERE datname IN ('app_test', 'app_test_test_1', 'app_test_test_2') AND pg_get_userbyid(datdba) = 'dlf_feature_x';\n";
            foreach (['app_test', 'app_test_test_1', 'app_test_test_2'] as $name) {
                $state .= "\\connect {$name}\nSELECT count(*) FROM pg_namespace, LATERAL aclexplode(nspacl) acl WHERE nspname = 'public' AND acl.grantee = (SELECT oid FROM pg_roles WHERE rolname = 'dlf_feature_x') AND acl.privilege_type IN ('USAGE', 'CREATE'); SELECT count(*) FROM pg_namespace, LATERAL aclexplode(nspacl) acl WHERE nspname = 'public' AND acl.grantee = 0 AND acl.privilege_type = 'CREATE';\n";
            }
            $state .= "\\connect app\nSELECT id FROM primary_data;\n\\connect app_test_test_2\nSELECT id FROM survivor;";
            foreach ([1, 2] as $attempt) {
                $this->postJson('/api/v1/database-connections', $request)->assertOk();
                expect($psql($state))->toBe($identities."\n".$primary."\n0\n3\n2\n0\n2\n0\n2\n0\n42\n17");
            }

        });
    })->with(['app_test', 'app_test_test_1']);

    it('refuses foreign pool members with 409 before restoring anything', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql);
            $psql('DROP DATABASE app_test; ALTER DATABASE app_test_test_2 OWNER TO orbit_test;');
            $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])
                ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
            expect($psql("SELECT datname FROM pg_database WHERE datname = 'app_test';"))->toBe('');
            expect($psql("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = 'app_test_test_2';"))->toBe('orbit_test');
        });
    });
});

describe('managed database removal preflight', function (): void {
    it('refuses a real PostgreSQL session on any planned database without dropping anything', function (string $busy): void {
        postgres_statements_server(function (Closure $psql, string $directory) use ($busy): void {
            server_database_live_postgres($this, $psql);
            $session = new PDO('pgsql:host='.$directory.';dbname='.$busy, 'orbit_test');

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', [$busy]);

            server_database_assert_pool_present($psql);
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();
            $session = null;
            postgres_wait_for_backend_exit($backend, $psql);
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();
            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe('0');
        });
    })->with(['app', 'app_test', 'app_test_test_2']);
    it('refuses a real logical slot before any drop whether active or inactive', function (bool $active): void {
        postgres_statements_server(function (Closure $psql, string $directory) use ($active): void {
            server_database_live_postgres($this, $psql);
            $psql("\\connect app_test_test_2
CREATE PUBLICATION orbit_removal; SELECT slot_name FROM pg_create_logical_replication_slot('orbit_removal', 'pgoutput');");
            $receiver = null;
            try {
                if ($active) {
                    $bin = rtrim(getenv('ORBIT_POSTGRES18_BIN') ?: '/usr/lib/postgresql/18/bin', '/');
                    $receiver = new SymfonyProcess([$bin.'/pg_recvlogical', '--host='.$directory, '--username=orbit_test', '--dbname=app_test_test_2', '--slot=orbit_removal', '--start', '--file=/dev/null', '--no-loop', '--option=proto_version=1', '--option=publication_names=orbit_removal', '--verbose']);
                    $receiver->start();
                    $receiver->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'streaming initiated'));
                    $psql("DO \$wait\$ BEGIN FOR attempt IN 1..100 LOOP EXIT WHEN (SELECT active FROM pg_replication_slots WHERE slot_name = 'orbit_removal'); PERFORM pg_sleep(0.01); END LOOP; END \$wait\$;");
                    $this->assertTrue($receiver->isRunning(), $receiver->getErrorOutput());
                    expect($psql("SELECT active::text FROM pg_replication_slots WHERE slot_name = 'orbit_removal';"))->toBe('true');
                }
                $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                    ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);
                server_database_assert_pool_present($psql);
                $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            } finally {
                $receiver?->stop();
            }
            $psql("SELECT pg_drop_replication_slot('orbit_removal');");
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();
        });
    })->with([false, true]);
    it('refuses outside role dependencies before any drop', function (string $dependency): void {
        postgres_statements_server(function (Closure $psql) use ($dependency): void {
            server_database_live_postgres($this, $psql);
            $psql($dependency);

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use');

            server_database_assert_pool_present($psql);
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
        });
    })->with([
        'database owner' => 'CREATE DATABASE outside OWNER dlf_feature_x;',
        'table owner' => 'CREATE TABLE outside_object (id int); ALTER TABLE outside_object OWNER TO dlf_feature_x;',
        'grant' => 'GRANT CONNECT ON DATABASE postgres TO dlf_feature_x;',
        'prepared transaction' => "\\connect app_test_test_2\nBEGIN; SELECT 1; PREPARE TRANSACTION 'orbit_removal';",
        'subscription' => "\\connect app_test_test_2\nCREATE SUBSCRIPTION orbit_removal CONNECTION '' PUBLICATION orbit_removal WITH (connect = false, slot_name = NONE);",
    ]);
    it('removes an Instance set with cross-database grants before dropping its distinct owners', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            server_database_live_postgres($this, $psql);
            $this->postJson('/api/v1/database-connections', ['slug' => 'other', 'server' => $this->server->slug])->assertCreated();
            DatabaseConnection::query()->where('slug', 'other')->update(['owner_instance_id' => $this->instance->id]);
            $psql("\\connect other\nCREATE TABLE shared_table (id int); GRANT SELECT ON shared_table TO dlf_feature_x;");
            $session = new PDO('pgsql:host='.$directory.';dbname=other', 'orbit_test');

            expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))
                ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.in_use'));

            server_database_assert_pool_present($psql);
            $this->assertDatabaseCount('database_connections', 2);
            $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();
            $session = null;
            postgres_wait_for_backend_exit($backend, $psql);

            app(DropOwnedDatabasesAction::class)->execute($this->instance->id);

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%' OR datname = 'other';"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_roles WHERE rolname IN ('dlf_feature_x', 'other');"))->toBe('0');
            $this->assertDatabaseCount('database_connections', 0);
        });
    });

    it('coordinates overlapping PostgreSQL removals across the complete two-server set', function (bool $contenderFirst): void {
        postgres_statements_server(function (Closure $first, string $firstDirectory) use ($contenderFirst): void {
            postgres_statements_server(function (Closure $second, string $secondDirectory) use ($first, $contenderFirst): void {
                $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
                $other = $this->server->replicate()->fill(['slug' => 'second-server', 'process_id' => null, 'port' => 5433]);
                $other->save();
                $hook = null;
                $commands = [];
                $admin = Mockery::mock(DatabaseServerAdmin::class);
                $admin->shouldReceive('execute')->andReturnUsing(function (DatabaseServer $server, string $sql) use ($first, $second, &$hook, &$commands): array {
                    $commands[] = $sql;
                    $output = ($server->id === $this->server->id ? $first : $second)($sql);
                    $hook?->__invoke($server, $sql, $output);

                    return $output === '' ? [] : explode("\n", $output);
                });
                app()->instance(DatabaseServerAdmin::class, $admin);
                foreach (['app' => $this->server, 'other' => $other] as $slug => $server) {
                    $this->postJson('/api/v1/database-connections', ['slug' => $slug, 'server' => $server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
                }
                DatabaseConnectionTarget::query()->delete();
                $connection = DatabaseConnection::query()->where('slug', 'other')->firstOrFail();
                $session = $contenderFirst ? new PDO('pgsql:host='.$secondDirectory.';dbname=other_test_test_2', 'orbit_test') : null;
                $snapshot = "SELECT datname, datallowconn, datconnlimit FROM pg_database WHERE datname LIKE 'app%' OR datname LIKE 'other%' ORDER BY datname;";
                $before = [$first($snapshot), $second($snapshot)];
                $overlapped = false;
                $commands = [];
                $hook = function (DatabaseServer $server, string $sql, string $output) use ($contenderFirst, $other, $connection, $first, $second, $secondDirectory, &$hook, &$session, &$overlapped): void {
                    if ($contenderFirst && $server->id === $other->id && str_starts_with($sql, 'SELECT DISTINCT blocker')) {
                        expect($output)->toBe('other_test_test_2');
                        $hook = null;
                        $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();
                        $session = null;
                        postgres_wait_for_backend_exit($backend, $second);
                        expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))
                            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.server_in_use'));
                        $overlapped = true;
                    } elseif (! $contenderFirst && $server->id === $this->server->id && str_contains($sql, 'DROP DATABASE')) {
                        $hook = null;
                        expect($first("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
                        expect(fn () => app(DropServerDatabaseAction::class)->execute($connection))
                            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.server_in_use'));
                        expect(fn () => new PDO('pgsql:host='.$secondDirectory.';dbname=other_test_test_2', 'orbit_test'))->toThrow(PDOException::class);
                        expect($second("SELECT count(*) FROM pg_database WHERE datname LIKE 'other%';"))->toBe('4');
                        $this->assertDatabaseCount('database_connections', 2);
                        $overlapped = true;
                    }
                };
                if ($contenderFirst) {
                    expect(fn () => app(DropServerDatabaseAction::class)->execute($connection))
                        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.in_use'));
                    expect([$first($snapshot), $second($snapshot)])->toBe($before);
                    expect(implode("\n", $commands))->not->toContain('DROP DATABASE');
                    $this->assertDatabaseCount('database_connections', 2);
                }

                app(DropOwnedDatabasesAction::class)->execute($this->instance->id);

                expect($overlapped)->toBeTrue();
                expect([$first($snapshot), $second($snapshot)])->toBe(['', '']);
                $this->assertDatabaseCount('database_connections', 0);
                expect($second('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            });
        });
    })->with([true, false]);
    it('fences late PostgreSQL worker admission and restores exact settings on refusal', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            $session = null;
            $checks = 0;
            $connectionRefused = false;
            server_database_live_postgres($this, $psql, afterCommand: function (string $sql, string $output) use ($directory, &$session, &$checks, &$connectionRefused): void {
                if (str_starts_with($sql, 'SELECT DISTINCT blocker') && ++$checks === 1) {
                    expect($output)->toBe('');
                    $session = new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test');
                }
                if (str_contains($sql, 'INSERT INTO orbit_admin.database_removal_fences')) {
                    try {
                        new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test');
                    } catch (PDOException) {
                        $connectionRefused = true;
                    }
                }
            });
            $psql('ALTER DATABASE app ALLOW_CONNECTIONS false CONNECTION LIMIT 7; ALTER DATABASE app_test CONNECTION LIMIT 3; ALTER DATABASE app_test_test_1 ALLOW_CONNECTIONS false CONNECTION LIMIT 0;');
            $before = server_database_admission($psql);

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);

            expect($connectionRefused)->toBeTrue();
            expect(server_database_admission($psql))->toBe($before);
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            server_database_assert_pool_present($psql);
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();
            $session = null;
            postgres_wait_for_backend_exit($backend, $psql);

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    });

    it('keeps PostgreSQL admission closed between the final check and drops', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            $checks = 0;
            $attempted = false;
            server_database_live_postgres($this, $psql, afterCommand: function (string $sql, string $output) use ($directory, &$checks, &$attempted): void {
                if (str_starts_with($sql, 'SELECT DISTINCT blocker') && ++$checks === 2) {
                    expect($output)->toBe('');
                    $attempted = true;
                    expect(fn () => new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test'))->toThrow(PDOException::class);
                }
            });

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($attempted)->toBeTrue();
            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    });

    it('recovers a committed PostgreSQL fence after a crash by finishing or restoring on refusal', function (bool $busy): void {
        postgres_statements_server(function (Closure $psql, string $directory) use ($busy): void {
            server_database_live_postgres($this, $psql);
            $psql('ALTER DATABASE app ALLOW_CONNECTIONS false CONNECTION LIMIT 7; ALTER DATABASE app_test CONNECTION LIMIT 3;');
            $before = server_database_admission($psql);
            $session = $busy ? new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test') : null;
            $statements = new PostgresStatements;
            $psql($statements->fenceDatabases(['app', 'app_test', 'app_test_test_1', 'app_test_test_2']));
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('4');
            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%' AND datallowconn;"))->toBe('0');

            if ($busy) {
                $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                    ->assertJsonPath('error.code', 'database.in_use');
                expect(server_database_admission($psql))->toBe($before);
                expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
                server_database_assert_pool_present($psql);
                $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
                $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();
                $session = null;
                postgres_wait_for_backend_exit($backend, $psql);
            }
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    })->with([false, true]);

    it('waits for a disconnected fixture backend before retrying PostgreSQL removal', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            server_database_live_postgres($this, $psql);
            $session = new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test');
            $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();
            $resumed = false;
            expect(posix_kill($backend, SIGSTOP))->toBeTrue();

            try {
                $session = null;
                postgres_wait_for_backend_exit($backend, function (string $sql) use ($psql, $backend, &$resumed): string {
                    $output = $psql($sql);
                    if (! $resumed) {
                        expect($output)->toBe('t');
                        expect(posix_kill($backend, SIGCONT))->toBeTrue();
                        $resumed = true;
                    }

                    return $output;
                });

                $this->deleteJson('/api/v1/database-connections/app')->assertOk();

                expect($resumed)->toBeTrue()->and($session)->toBeNull();
                expect($psql("SELECT count(*) FROM pg_stat_activity WHERE pid = {$backend};"))->toBe('0');
                expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
                $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
            } finally {
                if (! $resumed) {
                    posix_kill($backend, SIGCONT);
                }
            }
        });
    });

    it('bounds waiting for a PostgreSQL fixture backend that is still connected', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            $session = new PDO('pgsql:host='.$directory.';dbname=postgres', 'orbit_test');
            $backend = (int) $session->query('SELECT pg_backend_pid();')->fetchColumn();

            try {
                expect(fn () => postgres_wait_for_backend_exit($backend, $psql))
                    ->toThrow(RuntimeException::class, 'The disposable PostgreSQL backend did not exit.');
                expect($psql("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = {$backend});"))->toBe('t');
            } finally {
                $session = null;
            }
            postgres_wait_for_backend_exit($backend, $psql);
        });
    });

    it('rolls back an uncommitted PostgreSQL fence when the admin session exits', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql);
            $before = server_database_admission($psql);
            $statements = new PostgresStatements;
            $psql(str_replace('COMMIT;', "\\quit\n", $statements->fenceDatabases(['app', 'app_test', 'app_test_test_1', 'app_test_test_2'])));

            expect(server_database_admission($psql))->toBe($before);
            expect($psql("SELECT to_regclass('orbit_admin.database_removal_fences') IS NULL;"))->toBe('t');
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();
        });
    });

    it('finishes a real PostgreSQL removal after an injected halfway failure', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql, failHalfway: true);

            $this->deleteJson('/api/v1/database-connections/app')->assertStatus(502)
                ->assertJsonPath('error.code', 'database.server_command_failed');

            expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app', 'app_test');"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app_test_test_1', 'app_test_test_2');"))->toBe('2');
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    });
});

/** Run the real SQL against a disposable PostgreSQL server instead of SSH. */
function server_database_live_postgres(TestCase $test, Closure $psql, bool $failHalfway = false, ?Closure $afterCommand = null): void
{
    $test->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $test->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $test->server->slug, 'instance_id' => $test->instance->id, 'test_processes' => 2])->assertCreated();
    DatabaseConnectionTarget::query()->delete();
    $connection = DatabaseConnection::query()->where('slug', 'app')->sole();
    $statements = new PostgresStatements;
    $sql = $statements->ensureUser($connection->username, $connection->password);
    foreach (['app', 'app_test', 'app_test_test_1', 'app_test_test_2'] as $name) {
        $sql .= $statements->createDatabase($name).$statements->grantAll($name, $connection->username);
    }
    $psql($sql);
    $admin = Mockery::mock(DatabaseServerAdmin::class);
    $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($psql, &$failHalfway, $afterCommand): array {
        if ($failHalfway && str_contains($sql, 'DROP DATABASE')) {
            $failHalfway = false;
            $psql('DROP DATABASE IF EXISTS "app"; DROP DATABASE IF EXISTS "app_test";');
            throw new ResourceOperationException('database.server_command_failed', 'Simulated transport failure.', 502);
        }
        $output = $psql($sql);
        $afterCommand?->__invoke($sql, $output);

        return $output === '' ? [] : explode("\n", $output);
    });
    app()->instance(DatabaseServerAdmin::class, $admin);
}

function server_database_assert_pool_present(Closure $psql): void
{
    expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app', 'app_test', 'app_test_test_1', 'app_test_test_2');"))->toBe('4');
    expect($psql("SELECT count(*) FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe('1');
}

function server_database_admission(Closure $psql): string
{
    return $psql("SELECT datname || ':' || datallowconn::text || ':' || datconnlimit FROM pg_database WHERE datname IN ('app', 'app_test', 'app_test_test_1', 'app_test_test_2') ORDER BY datname;");
}
