<?php

declare(strict_types=1);

use App\Services\Executor\OperationStdinBuffer;
use App\Services\Executor\OperationTokenGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Representative internal commands must consume the original operation payload
 * after OperationTokenGuard drains stdin during verification.
 */
describe('internal operation stdin payload binding', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance(OperationTokenGuard::class);
        app()->forgetInstance(OperationStdinBuffer::class);
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('delivers the original payload to internal:tool:run-script after token verification', function (): void {
        $payload = json_encode([
            'tool' => 'node-exporter',
            'action' => 'install',
            'script' => 'printf bound-after-verify',
        ], JSON_THROW_ON_ERROR);

        $token = signed_internal_operation_token(command: 'internal:tool:run-script');

        [$exitCode, $output] = run_internal_command_with_stdin(
            commandName: 'internal:tool:run-script',
            parameters: [
                '--operation-token' => $token,
                '--json' => true,
            ],
            stdin: $payload,
        );

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['stdout'] ?? null)
            ->toBe('bound-after-verify');

        assert_verify_request_bound_input(
            token: $token,
            command: 'internal:tool:run-script',
            boundInput: $payload,
        );
    });

    it('delivers the original payload to internal:firewall-rule validation after token verification', function (): void {
        $payload = json_encode([
            'action' => 'apply',
            'shape' => [
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => 'any',
                'destination' => null,
                'port' => '../../../etc/passwd',
                'protocol' => 'tcp',
                'address_family' => 'both',
                'interface' => null,
            ],
        ], JSON_THROW_ON_ERROR);

        $token = signed_internal_operation_token(command: 'internal:firewall-rule');

        [$exitCode, $output] = run_internal_command_with_stdin(
            commandName: 'internal:firewall-rule',
            parameters: [
                '--operation-token' => $token,
                '--json' => true,
            ],
            stdin: $payload,
        );

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        // Payload must reach the handler (typed validation), not disappear as empty stdin.
        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($decoded['error']['message'] ?? null)
            ->toBe('Firewall rule shape is invalid.');

        assert_verify_request_bound_input(
            token: $token,
            command: 'internal:firewall-rule',
            boundInput: $payload,
        );
    });

    it('delivers the original payload to internal:schedule:run after token verification', function (): void {
        $payload = json_encode([
            'execution_type' => 'command',
            'execution_value' => 'printf schedule-bound',
            'cwd' => null,
            'timeout' => 60,
        ], JSON_THROW_ON_ERROR);

        $token = signed_internal_operation_token(command: 'internal:schedule:run');

        [$exitCode, $output] = run_internal_command_with_stdin(
            commandName: 'internal:schedule:run',
            parameters: [
                '--operation-token' => $token,
                '--json' => true,
            ],
            stdin: $payload,
        );

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['stdout'] ?? null)
            ->toBe('schedule-bound');

        assert_verify_request_bound_input(
            token: $token,
            command: 'internal:schedule:run',
            boundInput: $payload,
        );
    });
});

function signed_internal_operation_token(string $command): string
{
    return new OperationTokenSigner()
        ->sign(
            secret: implode(separator: '-', array: ['gateway', 'secret']),
            id: 'stdin-bind-'.str_replace(search: ':', replace: '-', subject: $command),
            node: 'app-dev',
            command: $command,
            issuedAt: time() - 10,
            expiresAt: time() + 120,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_command_with_stdin(string $commandName, array $parameters, string $stdin): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()[$commandName] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException("Command [{$commandName}] is not registered.");
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function assert_verify_request_bound_input(
    #[SensitiveParameter]
    string $token,
    string $command,
    string $boundInput,
): void {
    Http::assertSent(static function (Request $request) use ($token, $command, $boundInput): bool {
        $operationToken = $request['operation_token'] ?? null;
        $input = $request['input'] ?? null;

        return (
            $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
            && is_string($operationToken)
            && hash_equals($token, $operationToken)
            && ($request['command'] ?? null) === $command
            && is_string($input)
            && hash_equals($boundInput, $input)
        );
    });
}
