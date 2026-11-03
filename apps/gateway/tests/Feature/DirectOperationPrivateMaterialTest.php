<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tools\ToolIntentProjection;
use App\Infrastructure\AgentCommands\AgentGitHubToken;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->services = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.17']);
    $this->services->accessibleNodes()->attach($this->gateway);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'shared-private-proof', 'label' => 'test', 'node_id' => $this->gateway->id, 'url' => 'http://t3.test', 'admin_session' => 'shared-private-admin-sentinel', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->pairing = ConnPairing::query()->create(['node_id' => $this->gateway->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'fixture', 'pairing_link_id' => 'link', 'issue_started_at' => now()]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'conn.pairing_create', 'target_type' => 'conn_pairing', 'target_id' => $this->pairing->id, 'node_id' => $this->gateway->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['private_writer' => 'direct', 'swamp_run_id' => $this->run], 'input' => ['conn_environment_id' => $this->environment->id, 'environment' => ['$secret' => 'conn.environment', 'id' => $this->environment->id], 'caller_node_id' => $this->gateway->id, 'pairing_ids' => [$this->pairing->id]]]);
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, ['conn.pairing_create' => ['workflow' => 'operation-private-test', 'writer_config' => 'swamp.direct_writers', 'writer_state' => 'private_writer', 'projection' => ToolIntentProjection::class,
        'private_material' => ['environment' => 'conn.environment'], 'secret_outputs' => ['credential' => 'conn.pairing.credential']]]);
    app()->instance(DirectOperation::class, $direct);
    config(['dependency_detection.services_node_id' => $this->services->id, 'operation_executor.worker_node_ids' => [$this->gateway->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->output = ['run_id' => $this->run, 'outputs' => ['credential' => 'shared-output-sentinel']];
});

it('returns declared typed private credentials in the native wire shape', function (string $parameter, bool $hasToken): void {
    $token = $hasToken ? 'private-wire-github-sentinel' : null;
    $gitRead = $token === null ? GitReadEnvironment::none() : GitReadEnvironment::forGitHubToken($token);
    $plain = [false, null, '', [], ['count' => 7]];
    $value = $parameter === 'destruction'
        ? ['git_read' => $gitRead, 'plain' => $plain, 'installation_token' => new AgentGitHubToken('private-wire-installation-sentinel')]
        : $gitRead;
    $expected = $parameter === 'destruction'
        ? ['git_read' => ['github_token' => $token], 'plain' => $plain, 'installation_token' => 'private-wire-installation-sentinel']
        : ['github_token' => $token];
    $source = new readonly class($value) implements OperationSecretSource
    {
        public function __construct(#[SensitiveParameter] private mixed $value) {}

        public function name(): string
        {
            return 'fixture.typed_wire';
        }

        public function targetTypes(): array
        {
            return ['conn_pairing'];
        }

        public function ownerId(Operation $operation): ?int
        {
            return $operation->target_id;
        }

        public function resolve(Operation $operation, int $ownerId, string $key, string $parameter): OperationSecretValue
        {
            return new OperationSecretValue($this->value);
        }
    };
    app()->instance(OperationSecrets::class, new OperationSecrets([$source], app(LogRedactor::class)));
    $reflection = new ReflectionClass(DirectOperation::class);
    $definitions = $reflection->getProperty('definitions')->getValue(app(DirectOperation::class));
    $definitions['conn.pairing_create']['private_material'] = [$parameter => $source->name()];
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, $definitions);
    app()->instance(DirectOperation::class, $direct);
    app()->forgetInstance(DirectOperationPrivateMaterial::class);
    $input = ['undeclared' => 'outside-the-private-map'];
    Arr::set($input, $parameter, ['$secret' => $source->name(), 'id' => $this->pairing->id]);
    $this->operation->update(['input' => $input]);
    $before = $this->operation->fresh()->getAttributes();
    $expectedData = [];
    Arr::set($expectedData, $parameter, $expected);

    $response = $this->getJson($this->path.'/private-material?run_id='.$this->run)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data'))->toBe($expectedData);
    expect($response->getContent())->not->toContain('GIT_CONFIG_')->not->toContain('outside-the-private-map');
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('private-wire-github-sentinel')->not->toContain('private-wire-installation-sentinel');
    Http::assertNothingSent();
})->with([
    'top-level token' => ['git_read', true],
    'top-level anonymous' => ['git_read', false],
    'nested declaration token' => ['destruction.git_read', true],
    'nested declaration anonymous' => ['destruction.git_read', false],
    'nested source value token' => ['destruction', true],
    'nested source value anonymous' => ['destruction', false],
]);

it('rejects foreign and missing native bindings without establishing a run', function (): void {
    $foreign = '20e8138a-cf7f-4c6c-85fd-ff561b575ac8';
    $this->getJson($this->path.'/private-material?run_id='.$foreign)->assertConflict()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('error.code', 'operation.run_mismatch');
    $this->postJson($this->path.'/secret-output', [...$this->output, 'run_id' => $foreign])->assertConflict();
    $this->operation->update(['state' => ['private_writer' => 'direct']]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($this->path.'/secret-output', $this->output)->assertConflict();
    expect($this->operation->fresh()->state)->toBe(['private_writer' => 'direct']);
    expect($this->pairing->fresh()->credential)->toBeNull();
});

it('has no Services fleet bypass on either private endpoint', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($this->path.'/secret-output', $this->output)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    expect($this->pairing->fresh()->credential)->toBeNull();
});

it('refuses every terminal status on reads and writes', function (OperationStatus $status): void {
    $this->operation->update(['status' => $status]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertConflict()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('error.code', 'operation.finished');
    $this->postJson($this->path.'/secret-output', $this->output)->assertConflict();
})->with([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled]);

it('rejects extra selectors, undeclared fields and foreign references', function (): void {
    $this->getJson($this->path.'/private-material?run_id='.$this->run.'&artifact=environment')->assertUnprocessable();
    $this->getJson($this->path.'/private-material?run_id='.$this->run.'&source=conn.environment')->assertUnprocessable();
    $this->postJson($this->path.'/secret-output', [...$this->output, 'store' => 'foreign.store'])->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($this->path.'/secret-output', ['run_id' => $this->run, 'outputs' => ['foreign' => 'shared-output-sentinel']])->assertUnprocessable();
    $this->operation->update(['input' => [...$this->operation->input, 'environment' => ['$secret' => 'conn.environment', 'id' => $this->environment->id + 1]]]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertUnprocessable();
    expect($this->pairing->fresh()->credential)->toBeNull();
});
