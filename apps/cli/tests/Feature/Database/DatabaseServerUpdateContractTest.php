<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

require_once __DIR__.'/../../Helpers/DatabaseOperationFixtures.php';

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

it('renders the password requirement from recorded responses', function (): void {
    foreach ([false, true] as $json) {
        $suffix = $json ? '.json' : '.human.txt';
        run_contract('database-servers/database-server-update/in-use', 'database:server:update', ['slug' => 'beast-valkey', '--require-password' => true, '--json' => $json], 'database-servers/database-server-update/in-use'.$suffix, 1);
        run_database_operation_contract('database-servers/database-server-update/default', 'database:server:update', ['slug' => 'beast-valkey', '--require-password' => true, '--json' => $json], 'database-servers/database-server-update/default'.$suffix, 0);
    }
});
