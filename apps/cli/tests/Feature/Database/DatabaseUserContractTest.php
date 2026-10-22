<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

/**
 * Contract tests replay recorded Gateway responses and compare the complete command
 * output with tests/Expected. A fixture change that reaches a command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=160');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('database user contract', function (): void {
    it('renders database:user:list from the recorded response', function (): void {
        run_contract('database-connections/database-user-list/default', 'database:user:list', ['slug' => 'dlf-leden'], 'database-connections/database-user-list/default.human.txt', 0);
        run_contract('database-connections/database-user-list/default', 'database:user:list', ['slug' => 'dlf-leden', '--json' => true], 'database-connections/database-user-list/default.json', 0);
    });

    it('renders a PostgreSQL replication user from recorded create and list responses', function (): void {
        run_contract('database-connections/database-user-create/replication', 'database:user:create', ['slug' => 'catalog', '--username' => 'sync_reader', '--password' => 'fixture-secret', '--replication' => true], 'database-connections/database-user-create/replication.human.txt', 0);
        run_contract('database-connections/database-user-create/replication', 'database:user:create', ['slug' => 'catalog', '--username' => 'sync_reader', '--password' => 'fixture-secret', '--replication' => true, '--json' => true], 'database-connections/database-user-create/replication.json', 0);
        run_contract('database-connections/database-user-list/replication', 'database:user:list', ['slug' => 'catalog'], 'database-connections/database-user-list/replication.human.txt', 0);
        run_contract('database-connections/database-user-list/replication', 'database:user:list', ['slug' => 'catalog', '--json' => true], 'database-connections/database-user-list/replication.json', 0);
    });
});
