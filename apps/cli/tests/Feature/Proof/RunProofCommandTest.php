<?php

declare(strict_types=1);

use App\Commands\Proof\RunProofCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Support\ExtensionCommandVisibility;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\Operations\CancelOperationRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Proof\RunProofRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/../../Support/ProofRunMocks.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    MockClient::destroyGlobal();
    GatewayExtensionState::reset();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proof-'.Str::uuid();
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
    GatewayExtensionState::reset();
    Sleep::fake(false);
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('proof:run', function (): void {
    it('follows a passing proof and prints each case with its verdict, the VM and the receipt', function (bool $json): void {
        $mock = proof_run_gateway([proof_run_running(), proof_run_operation('passed')]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--json' => $json, '--no-interaction' => true]))->toBe(0);
        $output = Artisan::output();

        if ($json) {
            $payload = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
            expect(substr_count(trim($output), "\n"))->toBe(0)
                ->and($payload['status'])->toBe('succeeded')
                ->and($payload['result']['commit'])->toBe(PROOF_RUN_SHA)
                ->and($payload['result']['cases'][0])->toBe(['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'case' => 'new-restricted-allow', 'verdict' => 'passed', 'seconds' => 12.4])
                ->and($payload['result']['uncovered'][0]['path'])->toBe('swamp/workflows/workflow-vault-sync.yaml')
                ->and($payload['result']['vm'])->toBe('tvm-17')
                ->and($payload['result']['receipt_url'])->toBe('https://proof.orbit/receipts/0198e15c-bf97-7c23-8f1f-61b8fe67a846');
        } else {
            expect($output)->toContain(
                'Prove commit 0123456789ab',
                'Proof passed.',
                'firewall:allow',
                'new-restricted-allow',
                '12.4 s',
                'Uncovered: swamp/workflows/workflow-vault-sync.yaml (none: no case yet)',
                'VM: tvm-17',
                'Receipt: https://proof.orbit/receipts/0198e15c-bf97-7c23-8f1f-61b8fe67a846',
            )->not->toContain("\e");
        }
        $mock->assertSentCount(1, RunProofRequest::class);
    })->with([false, true]);

    it('fails with the failed and the unrun case named and the receipt link', function (bool $json): void {
        proof_run_gateway([proof_run_operation('failed')]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--json' => $json, '--no-interaction' => true]))->toBe(1);

        if ($json) {
            $payload = proof_run_json_output();
            expect($payload['status'])->toBe('failed')
                ->and($payload['error_code'])->toBe('proof.cases_failed')
                ->and(array_column($payload['result']['cases'], 'verdict'))->toBe(['passed', 'failed', 'unrun']);
        } else {
            expect(Artisan::output())->toContain(
                'Proof failed.',
                'deny-leaves-rule',
                'remove-needs-clean-fleet',
                'failed',
                'unrun',
                'Error code: proof.cases_failed',
                'Receipt: https://proof.orbit/receipts/0198e15c-bf97-7c23-8f1f-61b8fe67a846',
            );
        }
    })->with([false, true]);

    it('fails with every case unrun when the test fleet is not ready', function (): void {
        proof_run_gateway([proof_run_operation('run-failed')]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Proof failed.', 'unrun', 'Error code: proof.run_failed', 'Failed step: reset-fleet');
    });

    it('fails with proof.no_vm after the wait and no case result', function (): void {
        proof_run_gateway([proof_run_operation('no-vm')]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Proof failed.', 'Error code: proof.no_vm');
    });

    it('follows a proof refused at admission to its end and prints the reason from the Operation', function (): void {
        $refused = [...proof_run_operation('no-vm'), 'error_code' => 'tasks.capacity_unavailable', 'result' => ['commit' => PROOF_RUN_SHA, 'outcome' => 'failed', 'cases' => [], 'uncovered' => [], 'receipt_url' => null],
            'error' => ['code' => 'tasks.capacity_unavailable', 'detail' => 'Task VM: no host has 3 free slots for a large VM.']];
        proof_run_gateway([$refused]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Proof failed.', 'Reason: Task VM: no host has 3 free slots for a large VM.', 'Error code: tasks.capacity_unavailable');
    });

    it('skips a proof that nothing needs and exits 0', function (bool $json): void {
        proof_run_gateway([proof_run_operation('nothing-to-prove')]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--json' => $json, '--no-interaction' => true]))->toBe(0);

        if ($json) {
            $result = proof_run_json_output()['result'];
            expect($result['outcome'])->toBe('nothing_to_prove')->and($result['reason'])->toBe('proof.nothing_to_prove')->and($result['cases'])->toBe([]);
        } else {
            expect(Artisan::output())->toContain('Skipped — nothing to prove (proof.nothing_to_prove)', 'Uncovered: swamp/workflows/workflow-vault-sync.yaml')->not->toContain('VM:');
        }
    })->with([false, true]);

    it('sends all only when asked, and a generated key for each call', function (): void {
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--json' => true]);
        Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--all' => true, '--json' => true]);

        $sent = collect($mock->getRecordedResponses())
            ->map(static fn ($response) => $response->getPendingRequest())
            ->filter(static fn ($request): bool => $request instanceof RunProofRequest || $request->getRequest() instanceof RunProofRequest)
            ->values();
        $bodies = $sent->map(static fn ($request): array => $request->body()->all())->all();
        $keys = $sent->map(static fn ($request): string => $request->headers()->get('Idempotency-Key'))->all();

        expect($bodies)->toBe([['commit' => PROOF_RUN_SHA], ['commit' => PROOF_RUN_SHA, 'all' => true]])
            ->and($keys[0])->toMatch('/\Aproof\.run\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
            ->and($keys[1])->toMatch('/\Aproof\.run\.[0-9a-f-]{36}\z/')
            ->and($keys[1])->not->toBe($keys[0]);
    });

    it('sends report_only only when the run yields capacity', function (): void {
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--report-only' => true, '--json' => true]);

        $mock->assertSent(static fn ($request): bool => $request instanceof RunProofRequest && $request->body()->all() === ['commit' => PROOF_RUN_SHA, 'report_only' => true]);
    });

    it('sends keep_failed only when the VM of a failed run is to be kept', function (): void {
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--keep-failed' => true, '--json' => true]);

        $mock->assertSent(static fn ($request): bool => $request instanceof RunProofRequest && $request->body()->all() === ['commit' => PROOF_RUN_SHA, 'keep_failed' => true]);
    });

    it('prints the VM that a failed run kept, with its group and the time it is destroyed', function (): void {
        $failed = proof_run_operation('failed');
        $kept = ['command' => 'firewall:allow', 'vm' => 'tvm-600', 'group_id' => 3832, 'keep_until' => '2026-11-07T11:00:00Z'];
        proof_run_gateway([[...$failed, 'result' => [...$failed['result'], 'kept' => [$kept]]]]);

        Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--keep-failed' => true, '--no-interaction' => true]);

        expect(Artisan::output())->toContain('Kept VM: tvm-600 of firewall:allow, group 3832, destroyed after 2026-11-07T11:00:00Z');
    });

    it('sends the given idempotency key unchanged', function (): void {
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--idempotency-key' => 'ci.4711.1', '--json' => true]))->toBe(0);

        expect($mock->findResponseByRequest(RunProofRequest::class)?->getPendingRequest()->headers()->get('Idempotency-Key'))->toBe('ci.4711.1');
    });

    it('accepts a full 64 character commit', function (): void {
        $sha = str_repeat('fedcba98', 8);
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        expect(Artisan::call('proof:run', ['commit' => $sha, '--json' => true]))->toBe(0);
        $mock->assertSent(static fn ($request): bool => $request instanceof RunProofRequest && $request->body()->all() === ['commit' => $sha]);
    });

    it('refuses a commit that is not a full lowercase SHA before any request', function (string $commit): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('proof:run', ['commit' => $commit, '--json' => true, '--no-interaction' => true]))->toBe(1);

        $error = proof_run_json_output()['error'];
        expect($error['code'])->toBe('proof.commit_invalid')->and($error['details'])->toBe(['field' => 'commit']);
        $mock->assertNothingSent();
    })->with([
        'seven characters' => ['0123456'],
        'upper case' => [strtoupper(PROOF_RUN_SHA)],
        'branch name' => ['main'],
        'tag name' => ['v1.2.3'],
        'trailing space' => [PROOF_RUN_SHA.' '],
        'forty-one characters' => [PROOF_RUN_SHA.'a'],
    ]);

    it('refuses a missing commit without a terminal and with --json before any request', function (array $options): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('proof:run', [...$options, '--json' => true]))->toBe(1);

        expect(proof_run_json_output()['error']['code'])->toBe('proof.commit_invalid');
        $mock->assertNothingSent();

        expect(Artisan::call('proof:run', ['--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Name the commit by its full 40 or 64 character lowercase SHA.');
        $mock->assertNothingSent();
    })->with([[[]], [['--no-interaction' => true]]]);

    it('renders each Gateway refusal as the one-line envelope and follows nothing', function (string $fixture, string $code, array $details): void {
        $recorded = gateway_fixture('proof/proof-run/'.$fixture);
        $mock = MockClient::global([
            ...gateway_fixture_mock(),
            RunProofRequest::class => MockResponse::make($recorded['body'], $recorded['status'], ['Content-Type' => 'application/json']),
        ]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--json' => true]))->toBe(1);

        $error = proof_run_json_output()['error'];
        expect($error['code'])->toBe($code)->and($error['details'] ?? [])->toBe($details);
        $mock->assertSentCount(1);
        $mock->assertNotSent(ShowOperationRequest::class);
    })->with([
        'not found' => ['refused-not-found', 'proof.commit_not_found', []],
        'untrusted' => ['refused-untrusted', 'proof.commit_untrusted', []],
        'unmapped' => ['refused-unmapped', 'proof.path_unmapped', ['path' => 'apps/cli/app/Commands/Proof/NewKind.php']],
        'execution switched off' => ['refused-execution-disabled', 'proof.execution_disabled', [
            'commit' => PROOF_RUN_SHA,
            'cases' => ['swamp/proof/firewall/allow.cases.yaml'],
            'uncovered' => ['swamp/workflows/workflow-vault-sync.yaml'],
        ]],
        'extension off' => ['refused-extension-disabled', 'extension.disabled', []],
    ]);

    it('names the unmapped path in the human refusal', function (): void {
        $recorded = gateway_fixture('proof/proof-run/refused-unmapped');
        MockClient::global([RunProofRequest::class => MockResponse::make($recorded['body'], $recorded['status'], ['Content-Type' => 'application/json'])]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('A changed path matches no line of the selection list.', 'apps/cli/app/Commands/Proof/NewKind.php');
    });

    it('keeps the standard output free of terminal control codes when narrow and piped', function (): void {
        putenv('COLUMNS=40');
        proof_run_gateway([proof_run_operation('failed')]);

        Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA]);
        $output = Artisan::output();

        expect($output)->not->toContain("\e")->not->toContain("\r")
            ->and($output)->toContain('Proof failed.', 'Failed step: run-cases')
            ->and(array_filter(explode("\n", $output), static fn (string $line): bool => mb_strlen($line) > 40))->toBe([]);
    });

    it('belongs to the tasks extension and is hidden from listings while the extension is off', function (bool $enabled): void {
        MockClient::global([ListExtensionsRequest::class => MockResponse::make([
            'data' => ['tasks' => $enabled, 'ai-router' => true, 'documents' => true, 'vault' => true, 'conn' => true],
            'meta' => ['request_id' => 'test-discovery'],
        ])]);
        $command = app(RunProofCommand::class);

        expect($command->extensionSlug())->toBe('tasks')
            ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))->toBe(! $enabled)
            ->and($command->isHidden())->toBeFalse();
    })->with([true, false]);
});

describe('proof:run interrupts', function (): void {
    beforeEach(function (): void {
        if (! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
    });

    it('cancels the started run on Ctrl-C and SIGTERM and exits with the signal status', function (int $signal, int $status, string $mode): void {
        $mock = proof_run_gateway([], [
            ShowOperationRequest::class => static function () use ($signal): MockResponse {
                // The person presses Ctrl-C, or something terminates the process, while the cases run.
                posix_kill(getmypid(), $signal);

                return MockResponse::make(['data' => proof_run_running(), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]);
            },
            CancelOperationRequest::class => MockResponse::make(['data' => [...proof_run_running(), 'status' => 'cancelled', 'cancel_requested' => true, 'poll_after_seconds' => null], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]),
        ]);

        $exitCode = Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, $mode => true, '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe($status);
        if ($mode === '--json') {
            expect(trim($output))->toBe('');
        } else {
            expect($output)->toContain('Cancelling the operation. Follow it with: orbit operation:show 1')->not->toContain('keeps running');
        }
        $mock->assertSentCount(1, CancelOperationRequest::class);
        $mock->assertSent(static fn ($request): bool => $request instanceof CancelOperationRequest && $request->resolveEndpoint() === '/api/v1/operations/1/cancel');
    })->with([
        'Ctrl-C' => [SIGINT, 130],
        'SIGTERM' => [SIGTERM, 143],
    ])->with(['human' => '--no-ansi', 'JSON' => '--json']);

    it('still exits with the signal status and names the cancel command when the cancel request fails', function (): void {
        proof_run_gateway([], [
            ShowOperationRequest::class => static function (): MockResponse {
                posix_kill(getmypid(), SIGINT);

                return MockResponse::make(['data' => proof_run_running(), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]);
            },
            CancelOperationRequest::class => MockResponse::make(['error' => ['code' => 'operation.not_found', 'message' => 'No such operation.']], 404),
        ]);

        expect(Artisan::call('proof:run', ['commit' => PROOF_RUN_SHA, '--no-ansi' => true, '--no-interaction' => true]))->toBe(130)
            ->and(Artisan::output())->toContain('Could not cancel the operation (operation.not_found). Cancel it with: orbit operation:cancel 1');
    });
});
