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

describe('websocket app contract', function (): void {
    it('renders the websocket:app commands from the recorded responses', function (bool $json): void {
        $suffix = $json ? '.json' : '.human.txt';
        run_contract('websocket-apps/websocket-app-create/default', 'websocket:app:create', ['name' => 'hauzer', '--json' => $json], 'websocket-apps/websocket-app-create/default'.$suffix, 0);
        run_contract('websocket-apps/websocket-app-list/default', 'websocket:app:list', ['--json' => $json], 'websocket-apps/websocket-app-list/default'.$suffix, 0);
        run_contract('websocket-apps/websocket-app-show/default', 'websocket:app:show', ['name' => 'hauzer', '--json' => $json], 'websocket-apps/websocket-app-show/default'.$suffix, 0);
        run_contract('websocket-apps/websocket-app-destroy/in-use', 'websocket:app:destroy', ['name' => 'hauzer', '--force' => true, '--json' => $json], 'websocket-apps/websocket-app-destroy/in-use'.$suffix, 1);
        run_contract('websocket-apps/websocket-app-destroy/default', 'websocket:app:destroy', ['name' => 'hauzer', '--force' => true, '--json' => $json], 'websocket-apps/websocket-app-destroy/default'.$suffix, 0);
    })->with(['human' => false, 'json' => true]);

    it('renders the instance:websocket commands from the recorded responses', function (bool $json): void {
        $suffix = $json ? '.json' : '.human.txt';
        run_contract('websocket-apps/instance-websocket-add/default', 'instance:websocket:add', ['--instance' => '1', 'app' => 'hauzer', '--json' => $json], 'websocket-apps/instance-websocket-add/default'.$suffix, 0);
        run_contract('websocket-apps/instance-websocket-remove/default', 'instance:websocket:remove', ['--instance' => '1', 'app' => 'hauzer', '--force' => true, '--json' => $json], 'websocket-apps/instance-websocket-remove/default'.$suffix, 0);
    })->with(['human' => false, 'json' => true]);
});
