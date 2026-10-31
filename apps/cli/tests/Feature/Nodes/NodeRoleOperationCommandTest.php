<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows the role Operation and preserves terminal status idempotency and follow-ups', function (bool $json, string $status): void {
    $home = sys_get_temp_dir().'/orbit-role-operation-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 91, 'kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => 13,
        'status' => $status, 'sequence' => 3, 'input' => ['role' => 'ci-runner'], 'steps' => [],
        'error_code' => $status === 'failed' ? 'role.prerequisite_failed' : null,
        'result' => $status === 'succeeded' ? ['role_proven' => true, 'follow_up' => 'role-cli:cli.install_failed'] : [],
        'finished_at' => '2026-10-31T03:00:01Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);
    try {
        $exit = Artisan::call('node:role:add', ['node' => '13', 'role' => 'ci-runner', '--converge' => true,
            '--idempotency-key' => 'role-once', '--json' => $json]);
        $output = Artisan::output();
        expect($exit)->toBe($status === 'succeeded' ? 0 : 1, $output);
        if ($json) {
            $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            expect($data['kind'])->toBe('node.role_add')->and($data['status'])->toBe($status);
            if ($status === 'succeeded') {
                expect($data['result']['follow_up'])->toBe('role-cli:cli.install_failed');
            }
        } else {
            expect($output)->toContain('Node role', $status === 'succeeded' ? 'role-cli:cli.install_failed' : 'role.prerequisite_failed');
        }
        $mock->assertSent(fn ($request): bool => $request instanceof AddNodeRoleRequest
            && $request->headers()->get('Idempotency-Key') === 'role-once'
            && $request->body()->all() === ['role' => 'ci-runner', 'converge_existing' => true]);
        if ($json) {
            $mock->assertSent(ShowOperationRequest::class);
        }
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false])->with(['succeeded', 'failed']);
