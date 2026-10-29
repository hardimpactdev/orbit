<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Orbit\Sdk\Requests\Instances\SetupInstanceRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

it('renders the final setup Operation in JSON and preserves the retry key', function (): void {
    $home = sys_get_temp_dir().'/orbit-setup-operation-'.bin2hex(random_bytes(8));
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://gateway.test', caPath: '/unused/root.pem'));
    $final = ['data' => [
        'id' => 42, 'kind' => 'instance.run_steps', 'target_type' => 'instance', 'target_id' => 17,
        'input' => ['source' => 'project', 'list' => 'setup'], 'status' => 'succeeded', 'sequence' => 5,
        'current_step' => null, 'failed_step' => null, 'error_code' => null,
        'result' => ['outcome' => 'succeeded', 'instance_id' => 17, 'no_op' => true],
        'cancel_requested' => false, 'attempts' => 1, 'triggered_by' => 'operator',
        'created_at' => '2026-10-28T10:00:00+00:00', 'started_at' => '2026-10-28T10:00:01+00:00',
        'finished_at' => '2026-10-28T10:00:02+00:00', 'poll_after_seconds' => null,
    ], 'meta' => []];
    $mock = MockClient::global([
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit'], 'meta' => []]),
        SetupInstanceRequest::class => MockResponse::make($final, 202),
        ShowOperationRequest::class => MockResponse::make($final),
    ]);
    try {
        $exit = Artisan::call('instance:setup', ['instance' => '17', '--json' => true, '--idempotency-key' => 'setup-proof']);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(0)->and($output['kind'])->toBe('instance.run_steps')
            ->and($output['status'])->toBe('succeeded')->and($output['result']['no_op'])->toBeTrue();
        $mock->assertSent(fn (Request $request): bool => $request instanceof SetupInstanceRequest && $request->headers()->get('Idempotency-Key') === 'setup-proof');
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
});
