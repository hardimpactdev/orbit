<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\PostgresStatements;

it('sets replication explicitly and clears it for a regular login without exposing its password', function (): void {
    $statements = new PostgresStatements;

    expect($statements->ensureUser('sync_reader', 'pg-replication-secret-48df', replication: true))
        ->toContain('LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE REPLICATION PASSWORD', 'SCRAM-SHA-256$')
        ->not->toContain('pg-replication-secret-48df');
    expect($statements->ensureUser('sync_reader', 'pg-replication-secret-48df'))
        ->toContain('LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD');
    expect($statements->replicationAttribute('sync_reader'))
        ->toBe("SELECT rolreplication::text FROM pg_roles WHERE rolname = 'sync_reader';\n");
});
