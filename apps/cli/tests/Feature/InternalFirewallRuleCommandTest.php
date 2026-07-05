<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal firewall rule command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_firewall_rule_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid json payloads after token validation', function (): void {
        [$exitCode, $output] = run_internal_firewall_rule_command([
            '--operation-token' => firewall_rule_signed_operation_token(),
            '--json' => true,
        ], stdin: 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Firewall rule payload is invalid.',
            ));
    });

    it('rejects payloads outside the typed firewall shape', function (): void {
        [$exitCode, $output] = run_internal_firewall_rule_command(
            [
                '--operation-token' => firewall_rule_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
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
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Firewall rule shape is invalid.');
    });
});

function firewall_rule_signed_operation_token(
    string $id = 'firewall-rule',
    string $node = 'app-dev',
    string $command = 'internal:firewall-rule',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: firewall_rule_command_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function firewall_rule_command_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_firewall_rule_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:firewall-rule']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}
