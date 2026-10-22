<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\PostgresStatements;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('applies PostgreSQL statements with builtin locale, owner and current and future reader grants on PG18', function (): void {
    $bin = rtrim(getenv('ORBIT_POSTGRES18_BIN') ?: '/usr/lib/postgresql/18/bin', '/').'/';
    if (! is_executable($bin.'initdb')) {
        if (getenv('CI')) {
            $this->fail('The CI runner requires PostgreSQL 18 binaries.');
        }
        $this->markTestSkipped('PostgreSQL 18 binaries are unavailable.');
    }
    $directory = sys_get_temp_dir().'/orbit-pg-statements-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $run = static function (array $arguments, ?string $input = null) use ($directory): string {
        $process = new Process($arguments, env: ['PGHOST' => $directory, 'PGPORT' => '5432', 'PGUSER' => 'orbit_test', 'PGDATABASE' => 'postgres']);
        $process->setInput($input);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $psql = static fn (string $sql): string => $run([$bin.'psql', '-X', '-A', '-t', '-q', '-v', 'ON_ERROR_STOP=1'], $sql);
    $started = false;
    try {
        $run([$bin.'initdb', '-D', $directory.'/data', '-U', 'orbit_test', '--auth=trust', '--no-locale', '--encoding=UTF8', '--no-sync']);
        $run([$bin.'pg_ctl', '-D', $directory.'/data', '-l', $directory.'/log', '-o', '-F -k '.$directory." -h ''", '-w', 'start']);
        $started = true;
        $sql = new PostgresStatements;
        $psql($sql->ensureUser('catalog_owner', 'fixture-password').$sql->createDatabase('catalog', 'C.UTF-8').$sql->grantAll('catalog', 'catalog_owner'));
        expect($psql("SELECT datlocprovider::text || '|' || datlocale || '|' || pg_encoding_to_char(encoding) FROM pg_database WHERE datname = 'catalog';"))->toBe('b|C.UTF-8|UTF8');
        $psql("\\connect catalog\nSET ROLE catalog_owner; CREATE TABLE current_table (id bigint GENERATED ALWAYS AS IDENTITY, name text); INSERT INTO current_table (name) VALUES ('fixture'); RESET ROLE;\n");
        $psql($sql->ensureUser('catalog_reader', 'reader-password').$sql->replaceGrant('catalog', 'catalog_reader', false, 'catalog_owner'));
        $psql($sql->replaceGrant('catalog', 'catalog_reader', true, 'catalog_owner'));
        $psql("\\connect catalog\nSET ROLE catalog_owner; CREATE TABLE future_table (id bigint); RESET ROLE;\n");
        expect($psql("\\connect catalog\nSELECT has_table_privilege('catalog_reader','current_table','SELECT')::text || '|' || has_table_privilege('catalog_reader','current_table','INSERT')::text || '|' || has_table_privilege('catalog_reader','future_table','SELECT')::text || '|' || has_table_privilege('catalog_reader','future_table','INSERT')::text;"))->toBe('true|false|true|false');
        expect($psql("\\connect catalog\nSET ROLE catalog_reader; SELECT name FROM current_table;"))->toBe('fixture');
        $psql($sql->dropDatabase('catalog').$sql->dropUser('catalog_reader').$sql->dropUser('catalog_owner'));
    } finally {
        if ($started) {
            $run([$bin.'pg_ctl', '-D', $directory.'/data', '-m', 'immediate', '-w', 'stop']);
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});
