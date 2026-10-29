<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Models\DatabaseServer;
use Symfony\Component\Process\Exception\ProcessFailedException;

require_once __DIR__.'/../../../Support/PostgresServer.php';

it('refuses a restored foreign database before changing its owner or PUBLIC access on PG18', function (): void {
    postgres_statements_server(function (Closure $psql): void {
        $statements = new PostgresStatements;
        $server = new DatabaseServer(['driver' => 'pgsql']);
        $psql('CREATE ROLE reserved_owner; CREATE ROLE foreign_owner; CREATE DATABASE restored OWNER foreign_owner;');
        $before = $psql("SELECT datdba::text || '|' || COALESCE(datacl::text, 'null') FROM pg_database WHERE datname = 'restored';");
        $sql = DatabaseCommands::createSqlDatabase($server, 'restored', 'reserved_owner', null).$statements->grantAll('restored', 'reserved_owner');

        expect(fn () => $psql($sql))->toThrow(ProcessFailedException::class, 'database.name_conflict');

        expect($psql("SELECT datdba::text || '|' || COALESCE(datacl::text, 'null') FROM pg_database WHERE datname = 'restored';"))->toBe($before);
        $sql = DatabaseCommands::createSqlDatabase($server, 'managed', 'reserved_owner', null).$statements->grantAll('managed', 'reserved_owner');
        $psql($sql);
        $psql($sql);
        expect($psql("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = 'managed';"))->toBe('reserved_owner');
    });
});

it('preserves a restored foreign database and its admission when fenced removal or grants are attempted on PG18', function (): void {
    postgres_statements_server(function (Closure $psql): void {
        $sql = new PostgresStatements;
        $psql('CREATE ROLE reserved_owner; CREATE ROLE foreign_owner; CREATE DATABASE restored OWNER foreign_owner;');
        $before = $psql("SELECT datdba::text || '|' || datallowconn::text || '|' || COALESCE(datacl::text, 'null') FROM pg_database WHERE datname = 'restored';");

        expect(fn () => $psql($sql->dropFencedDatabases(['restored'], [], ['restored' => 'reserved_owner'])))
            ->toThrow(ProcessFailedException::class, 'database.name_conflict');
        expect(fn () => $psql($sql->grantAll('restored', 'reserved_owner')))
            ->toThrow(ProcessFailedException::class, 'database.name_conflict');

        expect($psql("SELECT datdba::text || '|' || datallowconn::text || '|' || COALESCE(datacl::text, 'null') FROM pg_database WHERE datname = 'restored';"))->toBe($before);
        $psql('CREATE DATABASE owned OWNER reserved_owner;');
        $psql($sql->dropFencedDatabases(['owned'], [], ['owned' => 'reserved_owner']));
        expect($psql("SELECT count(*) FROM pg_database WHERE datname = 'owned';"))->toBe('0');
    });
});
