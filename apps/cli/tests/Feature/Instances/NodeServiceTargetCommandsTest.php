<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\DetachInstanceNodeServiceTargetRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-service-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/home/orbit/.orbit/ca/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @return array<string, bool|int|string|null> */
function nodeServiceCliData(): array
{
    return ['id' => 4, 'instance_id' => 12, 'node_id' => 9, 'process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
        'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT', 'changed' => true, 'reason_code' => null];
}

describe('Node service target commands', function (): void {
    it('attaches through the typed request and prints clean JSON', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mock = MockClient::global([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => nodeServiceCliData(), 'meta' => ['request_id' => $requestId],
        ])]);

        $this->artisan('instance:node-service:attach', ['instance' => '12', '--process' => '221', '--port' => '1025',
            '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => true])
            ->expectsOutput(json_encode([...nodeServiceCliData(), 'request_id' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertExitCode(0);

        expect($mock->getLastRequest())->toBeInstanceOf(AttachInstanceNodeServiceTargetRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe(['process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
                'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']);
    });

    it('omits an unspecified port from the request', function (): void {
        $mock = MockClient::global([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => nodeServiceCliData(), 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);

        $this->artisan('instance:node-service:attach', ['instance' => '12', '--process' => '221',
            '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => true])->assertExitCode(0);

        expect($mock->getLastRequest()?->body()->all())->not->toHaveKey('port');
    });

    it('renders the completed record-only detach receipt without polling or realtime requests', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $result = [...nodeServiceCliData(), 'detached' => true];
        $mock = MockClient::global([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
                'status' => 'succeeded', 'sequence' => 2, 'result' => $result],
            'meta' => ['request_id' => $requestId],
        ], 202)]);

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true, '--json' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($output)->toMatchArray(['id' => 41, 'status' => 'succeeded', 'result' => $result, 'request_id' => $requestId]);
        $mock->assertSentCount(1);
        $mock->assertSent(fn ($request): bool => $request instanceof DetachInstanceNodeServiceTargetRequest
            && $request->resolveEndpoint() === '/api/v1/instances/12/node-service-targets/4'
            && Str::isUuid($request->headers()->get('Idempotency-Key')));
    });

    it('shows the completed receipt in human output', function (): void {
        $mock = MockClient::global([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
                'status' => 'succeeded', 'sequence' => 2, 'result' => [...nodeServiceCliData(), 'detached' => true]],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ], 202)]);

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('Node service target detached.', 'Operation', '41', 'succeeded')
            ->not->toContain('revoke', 'poll', "\e[");
        $mock->assertSentCount(1);
    });

    it('preserves Gateway detach refusals and request correlation', function (string $code, int $status): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mock = MockClient::global([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'error' => ['code' => $code, 'message' => 'Detach refused.', 'details' => []], 'meta' => ['request_id' => $requestId],
        ], $status, ['X-Orbit-Request-Id' => $requestId])]);

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true, '--json' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)->and($output['error'])->toMatchArray(['code' => $code, 'request_id' => $requestId]);
        $mock->assertSentCount(1);
    })->with([
        'missing or foreign' => ['instance.node_service_target_missing', 404],
        'active lifecycle' => ['instance.lifecycle_busy', 409],
        'failed migration retained runtime' => ['instance.node_service_runtime_cleanup_required', 409],
        'target lock' => ['operation.target_busy', 409],
    ]);

    it('rejects domain selectors and invalid target IDs before sending', function (array $input, string $code): void {
        $mock = MockClient::global();

        $exit = Artisan::call('instance:node-service:detach', [...$input, '--yes' => true, '--json' => true]);

        expect($exit)->toBe(1)->and(Artisan::output())->toContain($code);
        $mock->assertNothingSent();
    })->with([
        'domain' => [['instance' => 'app.example.test', 'target' => '4'], 'instance.id_invalid'],
        'target' => [['instance' => '12', 'target' => '0'], 'instance.node_service_target_id_invalid'],
    ]);

    it('reports an identical attach as skipped with its stable reason', function (bool $json): void {
        $data = [...nodeServiceCliData(), 'changed' => false, 'reason_code' => 'instance.node_service_target_unchanged'];
        $mock = MockClient::global([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);

        $exit = Artisan::call('instance:node-service:attach', ['instance' => '12', '--process' => '221',
            '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => $json, '--no-interaction' => true]);

        $output = Artisan::output();
        expect($exit)->toBe(0)->and($output)->toContain('instance.node_service_target_unchanged');
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toMatchArray($data);
        } else {
            expect($output)->toContain('Skipped');
        }
        $mock->assertSentCount(1);
    })->with([true, false]);

    it('requires consent before a machine detach sends a request', function (): void {
        $mock = MockClient::global();

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--json' => true]);

        expect($exit)->toBe(1);
        expect(Artisan::output())->toContain('instance.node_service_confirmation_required');
        $mock->assertNothingSent();
    });

    it('refuses invalid ports and missing required keys before sending', function (array $change, string $code): void {
        $mock = MockClient::global();
        $input = ['instance' => '12', '--process' => '221', '--port' => '1025', '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => true];

        $exit = Artisan::call('instance:node-service:attach', [...$input, ...$change]);

        expect($exit)->toBe(1);
        expect(Artisan::output())->toContain($code);
        $mock->assertNothingSent();
    })->with([
        'bad port' => [['--port' => '65536'], 'validation.failed'],
        'missing key' => [['--host-env-key' => ''], 'instance.node_service_host_key_required'],
    ]);
});
