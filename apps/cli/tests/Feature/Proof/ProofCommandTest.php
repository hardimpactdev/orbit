<?php

declare(strict_types=1);

use App\Commands\Proof\ProofCommand;
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
use Orbit\Sdk\Requests\Proof\VerifyProofRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/../../Support/ProofVerifyMocks.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    MockClient::destroyGlobal();
    GatewayExtensionState::reset();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proof-verify-'.Str::uuid();
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

describe('proof', function (): void {
    it('follows a passing proof and prints each case with its verdict', function (bool $json): void {
        $mock = proof_verify_gateway([proof_verify_running(), proof_verify_operation('passed')]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--json' => $json, '--no-interaction' => true]))->toBe(0);
        $output = Artisan::output();

        if ($json) {
            $payload = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
            expect(substr_count(trim($output), "\n"))->toBe(0)
                ->and($payload['kind'])->toBe('proof.verify')
                ->and($payload['status'])->toBe('succeeded')
                ->and($payload['result']['command'])->toBe('firewall:allow')
                ->and($payload['result']['outcome'])->toBe('passed')
                ->and($payload['result']['cases'][0])->toBe([
                    'program' => 'swamp/proof/firewall/allow.cases.yaml#new-restricted-allow',
                    'command' => 'firewall:allow',
                    'case' => 'new-restricted-allow',
                    'verdict' => 'passed',
                    'seconds' => 12.4,
                ]);
        } else {
            expect($output)->toContain('Prove firewall:allow', 'Proof passed.', 'firewall:allow', 'new-restricted-allow', 'already-applied', '12.4 s')->not->toContain("\e");
        }
        $mock->assertSentCount(1, VerifyProofRequest::class);
        $mock->assertSent(static fn ($request): bool => $request instanceof VerifyProofRequest && $request->body()->all() === ['command' => 'firewall:allow']);
    })->with([false, true]);

    it('sends the task group that --group names, and refuses one that is not a number', function (): void {
        $mock = proof_verify_gateway([proof_verify_running(), proof_verify_operation('passed')]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--group' => '3682', '--json' => true, '--no-interaction' => true]))->toBe(0);
        $mock->assertSent(static fn ($request): bool => $request instanceof VerifyProofRequest && $request->body()->all() === ['command' => 'firewall:allow', 'group' => 3682]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--group' => 'abc', '--json' => true, '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('proof.group_invalid');
        $mock->assertSentCount(1, VerifyProofRequest::class);
    });

    it('fails with the failed and the unrun case named', function (bool $json): void {
        proof_verify_gateway([proof_verify_operation('failed')]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--json' => $json, '--no-interaction' => true]))->toBe(1);

        if ($json) {
            $payload = proof_verify_json_output();
            expect($payload['status'])->toBe('failed')
                ->and($payload['error_code'])->toBe('proof.cases_failed')
                ->and(array_column($payload['result']['cases'], 'verdict'))->toBe(['passed', 'failed', 'unrun']);
        } else {
            expect(Artisan::output())->toContain('Proof failed.', 'protocols', 'port-range', 'failed', 'unrun', 'Error code: proof.cases_failed');
        }
    })->with([false, true]);

    it('fails with the one unrun row when the test fleet is not ready', function (): void {
        proof_verify_gateway([proof_verify_operation('run-failed')]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Proof failed.', 'allow.cases.yaml', 'unrun', 'Error code: proof.run_failed', 'Failed step: Check readiness');
    });

    it('fails when every case passed but the last restore failed, and says so', function (): void {
        proof_verify_gateway([proof_verify_operation('restore-failed')]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Proof failed.', 'new-restricted-allow', 'already-applied', 'Error code: proof.restore_failed', 'Failed step: Restore the clean fleet')
            ->and(Artisan::output())->not->toContain('Proof passed.');
    });

    it('sends a generated key for each call', function (): void {
        $mock = proof_verify_gateway([proof_verify_operation('passed')]);

        Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--json' => true]);
        Artisan::call('proof', ['orbit-command' => 'firewall:deny', '--json' => true]);

        $sent = collect($mock->getRecordedResponses())
            ->map(static fn ($response) => $response->getPendingRequest())
            ->filter(static fn ($request): bool => $request instanceof VerifyProofRequest || $request->getRequest() instanceof VerifyProofRequest)
            ->values();
        $bodies = $sent->map(static fn ($request): array => $request->body()->all())->all();
        $keys = $sent->map(static fn ($request): string => $request->headers()->get('Idempotency-Key'))->all();

        expect($bodies)->toBe([['command' => 'firewall:allow'], ['command' => 'firewall:deny']])
            ->and($keys[0])->toMatch('/\Aproof\.verify\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
            ->and($keys[1])->toMatch('/\Aproof\.verify\.[0-9a-f-]{36}\z/')
            ->and($keys[1])->not->toBe($keys[0]);
    });

    it('sends the given idempotency key unchanged', function (): void {
        $mock = proof_verify_gateway([proof_verify_operation('passed')]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--idempotency-key' => 'vm.4711.1', '--json' => true]))->toBe(0);

        expect($mock->findResponseByRequest(VerifyProofRequest::class)?->getPendingRequest()->headers()->get('Idempotency-Key'))->toBe('vm.4711.1');
    });

    it('accepts command names including nested command parts', function (string $command): void {
        $mock = proof_verify_gateway([proof_verify_operation('passed')]);

        expect(Artisan::call('proof', ['orbit-command' => $command, '--json' => true]))->toBe(0);
        $mock->assertSent(static fn ($request): bool => $request instanceof VerifyProofRequest && $request->body()->all() === ['command' => $command]);
    })->with(['task:pr-ready', 'firewall:allow', 'node:role:add', 'family:part:sub-command:action']);

    it('refuses a name that is not family:action before any request', function (string $command): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('proof', ['orbit-command' => $command, '--json' => true, '--no-interaction' => true]))->toBe(1);

        $error = proof_verify_json_output()['error'];
        expect($error['code'])->toBe('proof.command_invalid')->and($error['details'])->toBe(['field' => 'command']);
        $mock->assertNothingSent();
    })->with([
        'no colon' => ['doctor'],
        'upper case' => ['Firewall:allow'],
        'empty middle part' => ['node::add'],
        'empty final part' => ['node:role:'],
        'upper case nested part' => ['node:Role:add'],
        'nested path' => ['node:role:../add'],
        'a path' => ['firewall/allow'],
        'trailing space' => ['firewall:allow '],
        'empty action' => ['firewall:'],
    ]);

    it('refuses a missing command without a terminal and with --json before any request', function (): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('proof', ['--json' => true]))->toBe(1);
        expect(proof_verify_json_output()['error']['code'])->toBe('proof.command_invalid');
        $mock->assertNothingSent();

        expect(Artisan::call('proof', ['--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Name the command as family:action, for example firewall:allow.');
        $mock->assertNothingSent();
    });

    it('renders each Gateway refusal as the one-line envelope and follows nothing', function (string $fixture, string $code): void {
        $recorded = gateway_fixture('proof/proof-verify/'.$fixture);
        $mock = MockClient::global([
            ...gateway_fixture_mock(),
            VerifyProofRequest::class => MockResponse::make($recorded['body'], $recorded['status'], ['Content-Type' => 'application/json']),
        ]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--json' => true]))->toBe(1);

        expect(proof_verify_json_output()['error']['code'])->toBe($code);
        $mock->assertSentCount(1, VerifyProofRequest::class);
        $mock->assertNotSent(ShowOperationRequest::class);
    })->with([
        'not a task VM' => ['refused-no-task-vm', 'proof.no_task_vm'],
        'wrong project' => ['refused-wrong-project', 'proof.wrong_project'],
        'no workspace' => ['refused-no-workspace', 'tasks.no_workspace'],
        'target busy' => ['refused-target-busy', 'operation.target_busy'],
        'extension off' => ['refused-extension-disabled', 'extension.disabled'],
    ]);

    it('names the reason in the human refusal of a caller that is not a task VM', function (): void {
        $recorded = gateway_fixture('proof/proof-verify/refused-no-task-vm');
        MockClient::global([VerifyProofRequest::class => MockResponse::make($recorded['body'], $recorded['status'], ['Content-Type' => 'application/json'])]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('The proof runs on the task VM that calls it. This Node is not a task VM.');
    });

    it('keeps the standard output free of terminal control codes when narrow and piped', function (): void {
        putenv('COLUMNS=40');
        proof_verify_gateway([proof_verify_operation('failed')]);

        Artisan::call('proof', ['orbit-command' => 'firewall:allow']);
        $output = Artisan::output();

        expect($output)->not->toContain("\e")->not->toContain("\r")
            ->and($output)->toContain('Proof failed.', 'Failed step: batch-0')
            ->and(array_filter(explode("\n", $output), static fn (string $line): bool => mb_strlen($line) > 40))->toBe([]);
    });

    it('belongs to the tasks extension and is hidden from listings while the extension is off', function (bool $enabled): void {
        MockClient::global([ListExtensionsRequest::class => MockResponse::make([
            'data' => ['tasks' => $enabled, 'ai-router' => true, 'documents' => true, 'vault' => true, 'conn' => true],
            'meta' => ['request_id' => 'test-discovery'],
        ])]);
        $command = app(ProofCommand::class);

        expect($command->extensionSlug())->toBe('tasks')
            ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))->toBe(! $enabled)
            ->and($command->isHidden())->toBeFalse();
    })->with([true, false]);
});

describe('proof interrupts', function (): void {
    beforeEach(function (): void {
        if (! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
    });

    it('cancels the started proof on Ctrl-C and SIGTERM and exits with the signal status', function (int $signal, int $status, string $mode): void {
        $mock = proof_verify_gateway([], [
            ShowOperationRequest::class => static function () use ($signal): MockResponse {
                // The person presses Ctrl-C, or something terminates the process, while the cases run.
                posix_kill(getmypid(), $signal);

                return MockResponse::make(['data' => proof_verify_running(), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]);
            },
            CancelOperationRequest::class => MockResponse::make(['data' => [...proof_verify_running(), 'status' => 'cancelled', 'cancel_requested' => true, 'poll_after_seconds' => null], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]),
        ]);

        $exitCode = Artisan::call('proof', ['orbit-command' => 'firewall:allow', $mode => true, '--no-interaction' => true]);
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
        proof_verify_gateway([], [
            ShowOperationRequest::class => static function (): MockResponse {
                posix_kill(getmypid(), SIGINT);

                return MockResponse::make(['data' => proof_verify_running(), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]);
            },
            CancelOperationRequest::class => MockResponse::make(['error' => ['code' => 'operation.not_found', 'message' => 'No such operation.']], 404),
        ]);

        expect(Artisan::call('proof', ['orbit-command' => 'firewall:allow', '--no-ansi' => true, '--no-interaction' => true]))->toBe(130)
            ->and(Artisan::output())->toContain('Could not cancel the operation (operation.not_found). Cancel it with: orbit operation:cancel 1');
    });
});
