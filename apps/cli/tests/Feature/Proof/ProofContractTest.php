<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

require_once __DIR__.'/../../Support/ProofRunMocks.php';

/**
 * Contract tests replay the recorded proof responses and compare the complete command output
 * with tests/Expected/proof. A fixture change that reaches proof:run fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    $this->callerOrbitHome = config('orbit.home');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proof-contract-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    Sleep::fake(false);
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

describe('proof contract', function (): void {
    it('renders a followed run from the Gateway contract in human and JSON modes', function (string $read, int $exitCode): void {
        foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
            run_contract(
                ['proof/proof-run/queued', $read, 'realtime/realtime-show/unconfigured'],
                'proof:run',
                ['commit' => PROOF_RUN_SHA, '--idempotency-key' => 'proof.fixture', ...$mode],
                "{$read}.{$extension}",
                $exitCode,
            );
        }
    })->with([
        'every case passed' => ['proof/proof-run/passed', 0],
        'nothing to prove' => ['proof/proof-run/nothing-to-prove', 0],
        'a case failed and one did not run' => ['proof/proof-run/failed', 1],
        'the test fleet is not ready' => ['proof/proof-run/run-failed', 1],
        'no slot became free' => ['proof/proof-run/no-slot', 1],
    ]);

    it('renders a refusal from the Gateway contract in human and JSON modes', function (string $refusal): void {
        foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
            run_contract(
                $refusal,
                'proof:run',
                ['commit' => PROOF_RUN_SHA, '--idempotency-key' => 'proof.refused', ...$mode],
                "{$refusal}.{$extension}",
                1,
            );
        }
    })->with([
        'proof/proof-run/refused-not-found',
        'proof/proof-run/refused-untrusted',
        'proof/proof-run/refused-unmapped',
        'proof/proof-run/refused-execution-disabled',
        'proof/proof-run/refused-extension-disabled',
    ]);
});
