<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('S3Credentials CLI command', function (): void {
    // -----------------------------------------------------------------------
    // Request payload
    // -----------------------------------------------------------------------

    it('sends a GET request to /api/s3/credentials with the provided node', function (): void {
        fakeGateway(fakeS3CredentialsSuccessEnvelope('storage-1'));

        [$exitCode] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_contains(urldecode($request->url()), '/api/s3/credentials')
                && str_contains(urldecode($request->url()), 'node=storage-1')
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('sends the request without a node param when node auto-resolves from a single s3 node', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeS3NodeListEnvelope(['storage-1']),
                200,
            ),
            'https://gateway.test/api/s3/credentials*' => Http::response(
                fakeS3CredentialsSuccessEnvelope('storage-1'),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode] = runCommand($this, 's3:credentials', [
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_contains(urldecode($request->url()), '/api/s3/credentials')
                && str_contains(urldecode($request->url()), 'node=storage-1')
            ),
        );
    });

    // -----------------------------------------------------------------------
    // Node resolution: ambiguity and missing
    // -----------------------------------------------------------------------

    it('fails before contacting the credentials endpoint when node is ambiguous', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeS3NodeListEnvelope(['storage-1', 'storage-2']),
                200,
            ),
            'https://gateway.test/api/s3/credentials*' => Http::response(
                fakeS3CredentialsSuccessEnvelope('storage-1'),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/s3/credentials'));

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('node')
            ->and($decoded['error']['meta']['required_role'])
            ->toBe('s3');
    });

    it('fails before contacting the credentials endpoint when no s3 nodes exist', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeS3NodeListEnvelope([]),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('node')
            ->and($decoded['error']['meta']['required_role'])
            ->toBe('s3');
    });

    // -----------------------------------------------------------------------
    // JSON output shape
    // -----------------------------------------------------------------------

    it('emits the success envelope with all documented credential fields in --json mode', function (): void {
        fakeGateway(fakeS3CredentialsSuccessEnvelope('storage-1', [
            'access_key_id' => 'MYACCESSKEYID12345678',
            'secret_access_key' => 'my-secret-access-key-value',
        ]));

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['credentials']['node'])
            ->toBe('storage-1')
            ->and($decoded['success']['data']['credentials']['private_endpoint'])
            ->toBe('https://s3.orbit')
            ->and($decoded['success']['data']['credentials']['region'])
            ->toBe('orbit')
            ->and($decoded['success']['data']['credentials']['access_key_id'])
            ->toBe('MYACCESSKEYID12345678')
            ->and($decoded['success']['data']['credentials']['secret_access_key'])
            ->toBe('my-secret-access-key-value')
            ->and($decoded['success']['data']['credentials']['bucket_endpoint_style'])
            ->toBe('path')
            ->and($decoded['success']['meta']['tool'])
            ->toBe('seaweedfs');
    });

    it('places secret_access_key after access_key_id in --json output', function (): void {
        fakeGateway(fakeS3CredentialsSuccessEnvelope('storage-1'));

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        $akPos = strpos($output, 'access_key_id');
        $skPos = strpos($output, 'secret_access_key');

        expect($akPos)
            ->not->toBeFalse()->and($skPos)
            ->not->toBeFalse()->and($skPos)->toBeGreaterThan($akPos);
    });

    it('does not emit a progress tree in --json mode', function (): void {
        fakeGateway(fakeS3CredentialsSuccessEnvelope('storage-1'));

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->not->toContain('event: tree')->and($output)
            ->not->toContain('event: step')->and($output)
            ->not->toContain('event: complete');
    });

    // -----------------------------------------------------------------------
    // Human output
    // -----------------------------------------------------------------------

    it('outputs credential fields in human (non-JSON) mode', function (): void {
        fakeGateway(fakeS3CredentialsSuccessEnvelope('storage-1', [
            'access_key_id' => 'MYACCESSKEYID12345678',
            'secret_access_key' => 'my-secret-access-key-value',
            'public_endpoints' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('FIELD')
            ->and($output)
            ->toContain('VALUE')
            ->and($output)
            ->toContain('storage-1')
            ->and($output)
            ->toContain('https://s3.orbit')
            ->and($output)
            ->toContain('MYACCESSKEYID12345678')
            ->and($output)
            ->toContain('my-secret-access-key-value')
            ->and($output)
            ->toContain('—');
    });

    it('does not emit a progress tree in human mode', function (): void {
        fakeGateway(fakeS3CredentialsSuccessEnvelope('storage-1'));

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->not->toContain('event: tree')->and($output)
            ->not->toContain('event: step');
    });

    // -----------------------------------------------------------------------
    // API failure mapping
    // -----------------------------------------------------------------------

    it('maps s3.credentials_missing from the gateway in --json mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            's3.credentials_missing',
            "SeaweedFS service credentials are missing for 'storage-1'.",
            [
                'node' => 'storage-1',
                'tool' => 'seaweedfs',
                'next_command' => 'doctor --family=tool --restore --node=storage-1',
            ],
        ), 422);

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('s3.credentials_missing')
            ->and($decoded['error']['meta']['next_command'])
            ->toBe('doctor --family=tool --restore --node=storage-1');
    });

    it('maps authorization_failed from the gateway in --json mode', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Not authorized.'), 403);

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('authorization_failed');
    });

    it('maps validation_failed from the gateway in --json mode', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'An active router role is required.', [
            'field' => 'router',
            'required_role' => 'router',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('router');
    });

    it('maps gateway_unavailable errors when the gateway is unreachable', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 's3:credentials', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

// ---------------------------------------------------------------------------
// Test helper functions
// ---------------------------------------------------------------------------

/**
 * @param  list<string>  $nodeNames
 * @return array<string, mixed>
 */
function fakeS3NodeListEnvelope(array $nodeNames): array
{
    $nodes = array_map(fn (string $name): array => [
        'name' => $name,
        'status' => 'active',
        'roles' => [['role' => 's3', 'status' => 'active']],
    ], $nodeNames);

    return ['success' => ['data' => ['nodes' => $nodes], 'meta' => (object) []]];
}

/**
 * Build a fake s3:credentials success envelope matching the documented shape.
 *
 * @param  array<string, mixed>  $fieldOverrides
 * @return array<string, mixed>
 */
function fakeS3CredentialsSuccessEnvelope(string $node = 'storage-1', array $fieldOverrides = []): array
{
    $credentials = array_merge([
        'node' => $node,
        'private_endpoint' => 'https://s3.orbit',
        'public_endpoints' => [],
        'region' => 'orbit',
        'access_key_id' => 'TESTACCESSKEYID12345',
        'secret_access_key' => 'test-secret-access-key-value',
        'bucket_endpoint_style' => 'path',
        'backend_pool' => ["http://{$node}.s3.orbit:8333"],
    ], $fieldOverrides);

    return [
        'success' => [
            'data' => ['credentials' => $credentials],
            'meta' => ['tool' => 'seaweedfs'],
        ],
    ];
}
