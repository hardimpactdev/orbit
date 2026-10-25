<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\WebSocketApp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\WebSocket\AddInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ListWebSocketAppsRequest;
use Orbit\Sdk\Requests\WebSocket\RemoveInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketAppRequest;
use Tests\Support\FakesEnvironmentSyncInspection;

afterEach(function (): void {
    Str::createRandomStringsNormally();
    Str::createUuidsNormally();
});

/**
 * Records the WebSocket app responses that the CLI replays for `websocket:app:*` and `instance:websocket:*`.
 */
it('records the websocket app and attachment responses', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
    Http::preventStrayRequests();
    DB::table('websocket_registry')->update(['epoch' => '22222222-2222-4222-8222-222222222222']);
    Str::createUuidsUsingSequence(['33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444']);
    app()->instance(InstanceOperationPreflight::class, new WebSocketAppFixtureEnvironment);
    app()->instance(InstanceEnvironmentReader::class, new WebSocketAppFixtureEnvironment);
    app()->instance(InstanceEnvironmentWriter::class, new WebSocketAppFixtureEnvironment);

    $caller = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]);
    $this->markAsGateway($caller);
    $appNode = Node::query()->create([
        'name' => 'hauzer-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($appNode, false);
    $project = Project::query()->create([
        'name' => 'Hauzer',
        'slug' => 'hauzer',
        'repository_url' => 'https://example.test/hauzer.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $appNode->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/hauzer/default',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    WebSocketApp::query()->create([
        'name' => WebSocketApp::GatewayName,
        'app_id' => 'gatewayappid00000001',
        'key' => 'gatewayappkey0000001',
        'secret' => str_repeat('g', 40),
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', fixture_request_id());

    // The issuer draws the app id and the key as 20-character strings. No other random string reaches a response.
    $identifiers = ['hauzerappid000000001', 'hauzerappkey00000001'];
    Str::createRandomStringsUsing(static function (int $length) use (&$identifiers): string {
        return $length === 20 ? (string) array_shift($identifiers) : str_repeat('s', $length);
    });

    record_fixture(
        $this->withHeader('Idempotency-Key', 'fixture-create')->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertStatus(202),
        'websocket-apps/websocket-app-create/default',
        CreateWebSocketAppRequest::class,
        'POST /api/v1/websocket-apps',
    );

    record_fixture(
        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")->assertOk(),
        'websocket-apps/instance-websocket-add/default',
        AddInstanceWebSocketAppRequest::class,
        'PUT /api/v1/instances/{instance}/websocket-apps/{websocket_app}',
    );

    record_fixture(
        $this->getJson('/api/v1/websocket-apps')->assertOk(),
        'websocket-apps/websocket-app-list/default',
        ListWebSocketAppsRequest::class,
        'GET /api/v1/websocket-apps',
    );

    record_fixture(
        $this->getJson('/api/v1/websocket-apps/hauzer')->assertOk(),
        'websocket-apps/websocket-app-show/default',
        ShowWebSocketAppRequest::class,
        'GET /api/v1/websocket-apps/{websocket_app}',
    );

    record_fixture(
        $this->withHeader('Idempotency-Key', 'fixture-destroy')->deleteJson('/api/v1/websocket-apps/hauzer')->assertConflict(),
        'websocket-apps/websocket-app-destroy/in-use',
        DestroyWebSocketAppRequest::class,
        'DELETE /api/v1/websocket-apps/{websocket_app}',
    );

    record_fixture(
        $this->deleteJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")->assertOk(),
        'websocket-apps/instance-websocket-remove/default',
        RemoveInstanceWebSocketAppRequest::class,
        'DELETE /api/v1/instances/{instance}/websocket-apps/{websocket_app}',
    );

    record_fixture(
        $this->deleteJson('/api/v1/websocket-apps/hauzer')->assertStatus(202),
        'websocket-apps/websocket-app-destroy/default',
        DestroyWebSocketAppRequest::class,
        'DELETE /api/v1/websocket-apps/{websocket_app}',
    );

});

final class WebSocketAppFixtureEnvironment implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    use FakesEnvironmentSyncInspection;

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }
}
