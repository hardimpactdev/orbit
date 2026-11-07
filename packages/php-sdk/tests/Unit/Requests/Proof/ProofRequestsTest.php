<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Proof\RunProofRequest;
use Orbit\Sdk\Requests\Proof\VerifyProofRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

const PROOF_COMMIT = '0123456789abcdef0123456789abcdef01234567';

/** @return array{schema: int, request: string, route: string, status: int, body: array<string, mixed>} */
function proof_fixture(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 4)."/fixtures/proof/proof-run/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

function proof_send(Request $request, string $fixture): MockClient
{
    $recorded = proof_fixture($fixture);
    $mock = new MockClient([$request::class => MockResponse::make($recorded['body'], $recorded['status'])]);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient($mock);
    $connector->send($request)->dto();

    return $mock;
}

describe('proof requests', function (): void {
    it('starts proof.run with a JSON body request to the proof route', function (): void {
        $request = new RunProofRequest(PROOF_COMMIT, false, false, 'proof.run.c1');

        expect($request)->toBeInstanceOf(HasBody::class)
            ->and($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/proof/run');
    });

    it('sends report_only only when the run yields capacity', function (): void {
        $mock = proof_send(new RunProofRequest(PROOF_COMMIT, false, true, 'proof.run.c1'), 'queued');

        expect((string) $mock->getLastPendingRequest()?->createPsrRequest()->getBody())->toBe('{"commit":"'.PROOF_COMMIT.'","report_only":true}');
    });

    it('sends keep_failed only when the VM of a failed run is to be kept', function (): void {
        $mock = proof_send(new RunProofRequest(PROOF_COMMIT, false, false, 'proof.run.c1', true), 'queued');

        expect((string) $mock->getLastPendingRequest()?->createPsrRequest()->getBody())->toBe('{"commit":"'.PROOF_COMMIT.'","keep_failed":true}');
    });

    it('sends the key as a header and omits all unless it is set', function (string $commit, bool $all, string $body): void {
        $mock = proof_send(new RunProofRequest($commit, $all, false, 'proof.run.c1'), 'queued');
        $sent = $mock->getLastPendingRequest()?->createPsrRequest();

        expect((string) $sent?->getBody())->toBe($body)
            ->and($sent?->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($sent?->getHeaderLine('Idempotency-Key'))->toBe('proof.run.c1');
    })->with([
        'all off' => [PROOF_COMMIT, false, '{"commit":"'.PROOF_COMMIT.'"}'],
        'all on' => [PROOF_COMMIT, true, '{"commit":"'.PROOF_COMMIT.'","all":true}'],
        'sixty-four characters' => [str_repeat('fedcba98', 8), false, '{"commit":"'.str_repeat('fedcba98', 8).'"}'],
    ]);

    it('refuses a commit that is not a full lowercase SHA', function (string $commit): void {
        expect(static fn (): RunProofRequest => new RunProofRequest($commit, false, false, 'proof.run.c1'))->toThrow(InvalidArgumentException::class);
    })->with([
        'seven characters' => ['0123456'],
        'upper case' => [strtoupper(PROOF_COMMIT)],
        'branch' => ['main'],
        'tag' => ['v1.2.3'],
        'trailing space' => [PROOF_COMMIT.' '],
        'trailing newline' => [PROOF_COMMIT."\n"],
        'forty-one characters' => [PROOF_COMMIT.'a'],
        'empty' => [''],
    ]);
});

describe('proof responses from recorded Gateway fixtures', function (): void {
    it('reads the queued operation of an admitted run', function (): void {
        $recorded = proof_fixture('queued');
        $mock = new MockClient([RunProofRequest::class => MockResponse::make($recorded['body'], $recorded['status'])]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mock);

        $operation = $connector->send(new RunProofRequest(PROOF_COMMIT, false, false, 'proof.run.c1'))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->kind)->toBe('proof.run')
            ->and($operation->targetType)->toBe('proof')
            ->and($operation->status)->toBe('queued')
            ->and($operation->input)->toBe(['commit' => PROOF_COMMIT, 'all' => false])
            ->and($operation->terminal())->toBeFalse();
    });

    it('keeps the native result of each recorded run without interpreting it', function (string $fixture, string $status, ?string $errorCode, string $outcome): void {
        $recorded = proof_fixture($fixture);
        $mock = new MockClient([ShowOperationRequest::class => MockResponse::make($recorded['body'], $recorded['status'])]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mock);

        $operation = $connector->send(new ShowOperationRequest(1))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->status)->toBe($status)
            ->and($operation->errorCode)->toBe($errorCode)
            ->and($operation->result['outcome'] ?? null)->toBe($outcome === '' ? null : $outcome)
            ->and($operation->result)->toBe($recorded['body']['data']['result'])
            ->and($operation->toArray()['result'])->toBe($recorded['body']['data']['result']);
    })->with([
        'passed' => ['passed', 'succeeded', null, 'passed'],
        'nothing to prove' => ['nothing-to-prove', 'succeeded', null, 'nothing_to_prove'],
        'a case failed' => ['failed', 'failed', 'proof.cases_failed', 'failed'],
        'fleet not ready' => ['run-failed', 'failed', 'proof.run_failed', 'failed'],
        'no VM' => ['no-vm', 'failed', 'proof.no_vm', ''],
    ]);

    it('preserves the code and details of a recorded refusal', function (string $fixture, string $code, array $details): void {
        try {
            proof_send(new RunProofRequest(PROOF_COMMIT, false, false, 'proof.run.c1'), $fixture);
            test()->fail('The refusal did not throw.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code)
                ->and($exception->details())->toBe($details);
        }
    })->with([
        'not found' => ['refused-not-found', 'proof.commit_not_found', []],
        'untrusted' => ['refused-untrusted', 'proof.commit_untrusted', []],
        'unmapped' => ['refused-unmapped', 'proof.path_unmapped', ['path' => 'apps/cli/app/Commands/Proof/NewKind.php']],
        'execution off' => ['refused-execution-disabled', 'proof.execution_disabled', [
            'commit' => PROOF_COMMIT,
            'cases' => ['swamp/proof/firewall/allow.cases.yaml'],
            'uncovered' => ['swamp/workflows/workflow-vault-sync.yaml'],
        ]],
        'extension off' => ['refused-extension-disabled', 'extension.disabled', []],
    ]);
});

/** @return array{schema: int, request: string, route: string, status: int, body: array<string, mixed>} */
function proof_verify_fixture(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 4)."/fixtures/proof/proof-verify/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

function proof_verify_send(Request $request, string $fixture): MockClient
{
    $recorded = proof_verify_fixture($fixture);
    $mock = new MockClient([$request::class => MockResponse::make($recorded['body'], $recorded['status'])]);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient($mock);
    $connector->send($request)->dto();

    return $mock;
}

describe('proof verify requests', function (): void {
    it('names the task group in the body only when it is given', function (): void {
        expect((new VerifyProofRequest('firewall:allow', 'proof.verify.c1'))->body()->all())->toBe(['command' => 'firewall:allow'])
            ->and((new VerifyProofRequest('firewall:allow', 'proof.verify.c1', 3682))->body()->all())->toBe(['command' => 'firewall:allow', 'group' => 3682]);
    });

    it('starts proof.verify with a JSON body request to the verify route', function (): void {
        $request = new VerifyProofRequest('firewall:allow', 'proof.verify.c1');

        expect($request)->toBeInstanceOf(HasBody::class)
            ->and($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/proof/verify');
    });

    it('sends the command as the body and the key as a header', function (string $command): void {
        $mock = proof_verify_send(new VerifyProofRequest($command, 'proof.verify.c1'), 'queued');
        $sent = $mock->getLastPendingRequest()?->createPsrRequest();

        expect((string) $sent?->getBody())->toBe('{"command":"'.$command.'"}')
            ->and($sent?->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($sent?->getHeaderLine('Idempotency-Key'))->toBe('proof.verify.c1');
    })->with([
        'family and action' => ['firewall:allow'],
        'underscore in the family' => ['ai_router:status'],
        'hyphen in the action' => ['node:ssh-access'],
    ]);

    it('refuses a command that is not family:action', function (string $command): void {
        expect(static fn (): VerifyProofRequest => new VerifyProofRequest($command, 'proof.verify.c1'))->toThrow(InvalidArgumentException::class);
    })->with([
        'no colon' => ['doctor'],
        'empty' => [''],
        'upper case' => ['Firewall:allow'],
        'three segments' => ['firewall:rule:allow'],
        'leading digit' => ['1firewall:allow'],
        'empty action' => ['firewall:'],
        'leading hyphen in the action' => ['firewall:-allow'],
        'a path' => ['firewall/allow'],
        'trailing space' => ['firewall:allow '],
        'trailing newline' => ["firewall:allow\n"],
    ]);
});

describe('proof verify responses from recorded Gateway fixtures', function (): void {
    it('reads the queued operation of an admitted proof', function (): void {
        $recorded = proof_verify_fixture('queued');
        $mock = new MockClient([VerifyProofRequest::class => MockResponse::make($recorded['body'], $recorded['status'])]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mock);

        $operation = $connector->send(new VerifyProofRequest('firewall:allow', 'proof.verify.c1'))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->kind)->toBe('proof.verify')
            ->and($operation->targetType)->toBe('task_group')
            ->and($operation->status)->toBe('queued')
            ->and($operation->input['command'])->toBe('firewall:allow')
            ->and($operation->input['file'])->toBe('swamp/proof/firewall/allow.cases.yaml')
            ->and($operation->terminal())->toBeFalse();
    });

    it('keeps the native result of each recorded proof without interpreting it', function (string $fixture, string $status, ?string $errorCode, string $outcome): void {
        $recorded = proof_verify_fixture($fixture);
        $mock = new MockClient([ShowOperationRequest::class => MockResponse::make($recorded['body'], $recorded['status'])]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mock);

        $operation = $connector->send(new ShowOperationRequest(1))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->status)->toBe($status)
            ->and($operation->errorCode)->toBe($errorCode)
            ->and($operation->result['command'] ?? null)->toBe('firewall:allow')
            ->and($operation->result['outcome'] ?? null)->toBe($outcome)
            ->and($operation->result)->toBe($recorded['body']['data']['result'])
            ->and($operation->toArray()['result'])->toBe($recorded['body']['data']['result']);
    })->with([
        'passed' => ['passed', 'succeeded', null, 'passed'],
        'a case failed' => ['failed', 'failed', 'proof.cases_failed', 'failed'],
        'fleet not ready' => ['run-failed', 'failed', 'proof.run_failed', 'failed'],
        'last restore failed' => ['restore-failed', 'failed', 'proof.restore_failed', 'failed'],
    ]);

    it('preserves the code of a recorded refusal', function (string $fixture, string $code): void {
        try {
            proof_verify_send(new VerifyProofRequest('firewall:allow', 'proof.verify.c1'), $fixture);
            test()->fail('The refusal did not throw.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code)
                ->and($exception->details())->toBe([]);
        }
    })->with([
        'not a task VM' => ['refused-no-task-vm', 'proof.no_task_vm'],
        'wrong project' => ['refused-wrong-project', 'proof.wrong_project'],
        'no workspace' => ['refused-no-workspace', 'tasks.no_workspace'],
        'target busy' => ['refused-target-busy', 'operation.target_busy'],
        'extension off' => ['refused-extension-disabled', 'extension.disabled'],
    ]);
});
