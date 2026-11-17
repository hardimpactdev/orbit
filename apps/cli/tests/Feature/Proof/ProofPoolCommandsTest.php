<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Proof\DrainProofPoolRequest;
use Orbit\Sdk\Requests\Proof\ResumeProofPoolRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    GatewayExtensionState::reset();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proof-pool-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    GatewayExtensionState::reset();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('proof:pool:drain and proof:pool:resume', function (): void {
    it('sends one request and prints the pool, its warm copies and the copies of running proofs', function (string $command, string $request, bool $drained, string $first): void {
        $mock = MockClient::global([
            ...gateway_fixture_mock(),
            $request => MockResponse::make(['data' => ['drained' => $drained, 'warm_copies' => [12], 'proof_copies' => []], 'meta' => ['request_id' => 'pool-id']]),
        ]);

        expect(Artisan::call($command, ['--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain($first, 'Warm copies: 12', 'Copies of running proofs: none');
        $mock->assertSentCount(1, $request);

        expect(Artisan::call($command, ['--json' => true, '--no-interaction' => true]))->toBe(0)
            ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toBe(['drained' => $drained, 'warm_copies' => [12], 'proof_copies' => []]);
    })->with([
        'drain' => ['proof:pool:drain', DrainProofPoolRequest::class, true, 'The warm proof copy pool is drained.'],
        'resume' => ['proof:pool:resume', ResumeProofPoolRequest::class, false, 'The warm proof copy pool refills.'],
    ]);
});
