<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('S3Publish CLI command', function (): void {

    // -----------------------------------------------------------------------
    // Non-interactive: request payloads
    // -----------------------------------------------------------------------

    it('posts the correct payload to the gateway', function (): void {
        fakeGateway(fakeS3PublishSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/s3/public-hosts'
            && $request->data() === ['host' => 's3.example.com', 'node' => 'storage-1']);

        expect($exitCode)->toBe(0);
    });

    it('sends only the host when node auto-resolves from a single s3 node', function (): void {
        // First call: node list. Second call: publish.
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(fakeNodeListEnvelope(['storage-1']), 200),
            'https://gateway.test/api/s3/public-hosts' => Http::response(fakeS3PublishSuccessEnvelope(), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/s3/public-hosts'
            && $request->data()['host'] === 's3.example.com');
    });

    // -----------------------------------------------------------------------
    // Non-interactive: missing required inputs
    // -----------------------------------------------------------------------

    it('fails before contacting the gateway when host is missing in non-interactive mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('host');
    });

    it('fails before contacting the gateway when node is ambiguous in non-interactive mode', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeNodeListEnvelope(['storage-1', 'storage-2']),
                200,
            ),
            'https://gateway.test/api/s3/public-hosts' => Http::response(fakeS3PublishSuccessEnvelope(), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/api/s3/public-hosts');

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node');
    });

    it('fails before contacting the gateway when no s3 nodes exist', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(fakeNodeListEnvelope([]), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node')
            ->and($decoded['error']['meta']['required_role'])->toBe('s3');
    });

    // -----------------------------------------------------------------------
    // JSON output
    // -----------------------------------------------------------------------

    it('renders the success envelope as JSON with --json', function (): void {
        fakeGateway(fakeS3PublishSuccessEnvelope('s3.example.com', 'storage-1'));

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['s3']['node'])->toBe('storage-1')
            ->and($decoded['success']['data']['s3']['private_endpoint'])->toBe('https://s3.orbit')
            ->and($decoded['success']['meta']['host'])->toBe('s3.example.com')
            ->and($decoded['success']['meta']['action'])->toBe('published')
            ->and($decoded['success']['meta']['already_published'])->toBeFalse();
    });

    it('preserves gateway error envelopes through --json', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'An active router role is required for S3 routing.', [
            'field' => 'router',
            'required_role' => 'router',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('router')
            ->and($decoded['error']['meta']['required_role'])->toBe('router');
    });

    it('preserves proxy.domain_conflict error code through --json', function (): void {
        fakeGateway(fakeErrorEnvelope('proxy.domain_conflict', "The host 's3.example.com' is owned by a non-S3 proxy route.", [
            'field' => 'host',
            'owner_type' => 'app',
        ]), 409);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('proxy.domain_conflict');
    });

    it('preserves s3.publish_failed error code through --json', function (): void {
        fakeGateway(fakeErrorEnvelope('s3.publish_failed', 'Route apply failed.', []), 500);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('s3.publish_failed');
    });

    // -----------------------------------------------------------------------
    // Human output
    // -----------------------------------------------------------------------

    it('outputs human-readable success summary without --json', function (): void {
        fakeGateway(fakeS3PublishSuccessEnvelope('s3.example.com', 'storage-1'));

        $this->artisan('s3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
        ])->assertSuccessful();
    });

    // -----------------------------------------------------------------------
    // Interactive input mode
    // -----------------------------------------------------------------------

    it('prompts for the host when the host argument is omitted in interactive mode', function (): void {
        fakeGateway(fakeS3PublishSuccessEnvelope('prompted.example.com', 'storage-1'));

        $this->artisan('s3:publish', ['--node' => 'storage-1'])
            ->expectsQuestion('Public hostname (e.g. s3.example.com)', 'prompted.example.com')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/s3/public-hosts'
            && $request->data()['host'] === 'prompted.example.com');
    });
});

// ---------------------------------------------------------------------------
// Test helper functions
// ---------------------------------------------------------------------------

/**
 * @param  list<string>  $nodeNames
 * @return array<string, mixed>
 */
function fakeNodeListEnvelope(array $nodeNames): array
{
    $nodes = array_map(fn (string $name): array => [
        'name' => $name,
        'status' => 'active',
        'roles' => [['role' => 's3', 'status' => 'active']],
    ], $nodeNames);

    return ['success' => ['data' => ['nodes' => $nodes], 'meta' => (object) []]];
}

/**
 * @return array<string, mixed>
 */
function fakeS3PublishSuccessEnvelope(string $host = 's3.example.com', string $node = 'storage-1'): array
{
    return [
        'success' => [
            'data' => [
                's3' => [
                    'node' => $node,
                    'private_endpoint' => 'https://s3.orbit',
                    'public_endpoints' => ["https://{$host}"],
                    'backend_pool' => ["http://{$node}.s3.orbit:9000"],
                    'credentials_ref' => [
                        'tool' => 'rustfs',
                        'node' => $node,
                    ],
                ],
            ],
            'meta' => [
                'host' => $host,
                'action' => 'published',
                'already_published' => false,
            ],
        ],
    ];
}
