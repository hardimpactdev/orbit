<?php

declare(strict_types=1);

use App\Commands\Internal\SoloUpstreamRequestCommand;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Application as LaravelZeroApplication;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal solo upstream request command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading the upstream payload', function (): void {
        [$exitCode, $output] = run_internal_solo_upstream_request([
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('sends a typed HTTP request and returns status with base64 response body', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:24678/api/projects' => Http::response([
                'ok' => true,
                'data' => [
                    'projects' => [
                        ['id' => 1, 'name' => 'orbit'],
                    ],
                ],
            ]),
        ]);

        [$exitCode, $output] = run_internal_solo_upstream_request([
            '--operation-token' => solo_upstream_request_signed_operation_token(),
            '--json' => true,
        ], [
            'method' => 'POST',
            'url' => 'http://127.0.0.1:24678/api/projects',
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer secret-token',
                'X-Orbit-Node' => 'NMBP',
            ],
            'body' => [
                'name' => 'orbit',
            ],
        ]);

        $data = solo_upstream_request_success_data($output);
        $body = json_decode(
            base64_decode($data['body_base64'], strict: true) ?: '',
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($exitCode)
            ->toBe(0)
            ->and($data['status'])
            ->toBe(200)
            ->and($body['data']['projects'][0]['name'] ?? null)
            ->toBe('orbit');

        Http::assertSent(
            fn (Illuminate\Http\Client\Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'http://127.0.0.1:24678/api/projects'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request->hasHeader('X-Orbit-Node', 'NMBP')
                && $request['name'] === 'orbit'
            ),
        );
    });
});

function solo_upstream_request_signed_operation_token(
    string $id = 'solo-upstream-request',
    string $node = 'app-dev',
    string $command = 'internal:solo-upstream-request',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: solo_upstream_request_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function solo_upstream_request_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @param  array<string, mixed>  $payload
 * @return array{int, string}
 */
function run_internal_solo_upstream_request(array $parameters = [], array $payload = []): array
{
    $command = app(SoloUpstreamRequestCommand::class);
    $application = app();

    if (! $application instanceof LaravelZeroApplication) {
        throw new RuntimeException('The internal command test must run inside the Laravel Zero application.');
    }

    $command->setLaravel($application);
    $output = new BufferedOutput;
    $input = new ArrayInput($parameters);
    $input->setStream(fopen(
        filename: 'data://text/plain,'.rawurlencode(json_encode($payload, JSON_THROW_ON_ERROR)),
        mode: 'r',
    ));

    return [$command->run($input, $output), $output->fetch()];
}

/**
 * @return array<string, mixed>
 */
function solo_upstream_request_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $data */
    $data = data_get(target: $payload, key: 'success.data');

    if (! is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $data */
    return $data;
}
