<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

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
        websocket_registry_contract('websocket-apps/websocket-app-create/default', 'websocket:app:create', ['name' => 'hauzer', '--json' => $json], 'websocket-apps/websocket-app-create/default'.$suffix, 0);
        run_contract('websocket-apps/websocket-app-list/default', 'websocket:app:list', ['--json' => $json], 'websocket-apps/websocket-app-list/default'.$suffix, 0);
        run_contract('websocket-apps/websocket-app-show/default', 'websocket:app:show', ['name' => 'hauzer', '--json' => $json], 'websocket-apps/websocket-app-show/default'.$suffix, 0);
        run_contract('websocket-apps/websocket-app-destroy/in-use', 'websocket:app:destroy', ['name' => 'hauzer', '--force' => true, '--json' => $json], 'websocket-apps/websocket-app-destroy/in-use'.$suffix, 1);
        websocket_registry_contract('websocket-apps/websocket-app-destroy/default', 'websocket:app:destroy', ['name' => 'hauzer', '--force' => true, '--json' => $json], 'websocket-apps/websocket-app-destroy/default'.$suffix, 0);
    })->with(['human' => false, 'json' => true]);

    it('renders the instance:websocket commands from the recorded responses', function (bool $json): void {
        $suffix = $json ? '.json' : '.human.txt';
        run_contract('websocket-apps/instance-websocket-add/default', 'instance:websocket:add', ['--instance' => '1', 'app' => 'hauzer', '--json' => $json], 'websocket-apps/instance-websocket-add/default'.$suffix, 0);
        run_contract('websocket-apps/instance-websocket-remove/default', 'instance:websocket:remove', ['--instance' => '1', 'app' => 'hauzer', '--force' => true, '--json' => $json], 'websocket-apps/instance-websocket-remove/default'.$suffix, 0);
    })->with(['human' => false, 'json' => true]);
});

/** Replay admission and the normal Operation follower with a proven final read. */
function websocket_registry_contract(string $fixture, string $command, array $arguments, string $expected, int $exitCode): void
{
    $recorded = gateway_fixture($fixture);
    $body = json_decode($recorded['body'], true, flags: JSON_THROW_ON_ERROR);
    $body['data']['status'] = 'succeeded';
    $body['data']['result'] = ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']];
    MockClient::destroyGlobal();
    MockClient::global([
        ...gateway_fixture_mock($fixture),
        ShowOperationRequest::class => MockResponse::make($body),
        '*/api/v1/realtime' => MockResponse::make(['data' => []]),
    ]);
    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}
