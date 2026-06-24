<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayStreamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('S3Publish CLI command', function (): void {
    // -----------------------------------------------------------------------
    // Non-interactive: request payloads
    // -----------------------------------------------------------------------

    it('posts the correct payload to the streaming gateway endpoint', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Publishing S3 Host',
                'steps' => [['key' => 'resolve_node', 'label' => 'Resolve S3 node']],
            ])
                .gatewayProgressFrame('complete', s3PublishCompleteFrame()),
        );

        [$exitCode] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/s3/public-hosts'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === ['host' => 's3.example.com', 'node' => 'storage-1']
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('sends only the host when node auto-resolves from a single s3 node', function (): void {
        fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', s3PublishCompleteFrame()));

        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(fakeNodeListEnvelope(['storage-1']), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        [$exitCode] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/s3/public-hosts'
                && $request->data()['host'] === 's3.example.com'
            ),
        );
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

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('host');
    });

    it('fails before contacting the gateway when node is ambiguous in non-interactive mode', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeNodeListEnvelope(['storage-1', 'storage-2']),
                200,
            ),
            'https://gateway.test/api/s3/public-hosts' => Http::response(
                gatewayProgressFrame('complete', s3PublishCompleteFrame()),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNotSent(
            fn (Request $request): bool => $request->url() === 'https://gateway.test/api/s3/public-hosts',
        );

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('node');
    });

    it('fails before contacting the gateway when no s3 nodes exist', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(fakeNodeListEnvelope([]), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
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
    // JSON output — final frame
    // -----------------------------------------------------------------------

    it('emits only the final complete frame in --json mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Publishing S3 Host',
                'steps' => [['key' => 'resolve_node', 'label' => 'Resolve S3 node']],
            ]).gatewayProgressFrame('step', ['key' => 'resolve_node', 'status' => 'running'])
                .gatewayProgressFrame('complete', s3PublishCompleteFrame('s3.example.com', 'storage-1')),
        );

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and(count(array_filter(explode("\n", $output))))
            ->toBe(1)
            ->and($output)
            ->not->toContain('Publishing S3 Host')->and($output)
            ->not->toContain('Resolve S3 node');
    });

    it('renders the success envelope fields in --json mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', s3PublishCompleteFrame('s3.example.com', 'storage-1')),
        );

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['data']['data']['s3']['node'])
            ->toBe('storage-1')
            ->and($decoded['data']['data']['s3']['private_endpoint'])
            ->toBe('https://s3.orbit')
            ->and($decoded['data']['data']['meta']['host'])
            ->toBe('s3.example.com')
            ->and($decoded['data']['data']['meta']['action'])
            ->toBe('published')
            ->and($decoded['data']['data']['meta']['already_published'])
            ->toBeFalse();
    });

    it('preserves gateway error envelopes through --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'An active router role is required for S3 routing.',
                'data' => [
                    'code' => 'validation_failed',
                    'message' => 'An active router role is required for S3 routing.',
                    'meta' => ['field' => 'router', 'required_role' => 'router'],
                ],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['event'])->toBe('error');
    });

    it('preserves proxy.domain_conflict error code through --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => "The host 's3.example.com' is owned by a non-S3 proxy route.",
                'data' => [
                    'code' => 'proxy.domain_conflict',
                    'message' => "The host 's3.example.com' is owned by a non-S3 proxy route.",
                    'meta' => ['field' => 'host', 'owner_type' => 'app'],
                ],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)->and($output)->toContain('proxy.domain_conflict');
    });

    it('preserves s3.publish_failed error code through --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Route apply failed.',
                'data' => [
                    'code' => 's3.publish_failed',
                    'message' => 'Route apply failed.',
                    'meta' => [],
                ],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)->and($output)->toContain('s3.publish_failed');
    });

    // -----------------------------------------------------------------------
    // Human output
    // -----------------------------------------------------------------------

    it('renders the progress tree in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Publishing S3 Host',
                'steps' => [
                    ['key' => 'resolve_node', 'label' => 'Resolve S3 node'],
                    ['key' => 'check_router_ingress', 'label' => 'Check router and ingress'],
                    ['key' => 'ensure_credentials', 'label' => 'Ensure SeaweedFS credentials'],
                    ['key' => 'ensure_private_route', 'label' => 'Ensure private s3.orbit route'],
                    ['key' => 'ensure_backend_pool', 'label' => 'Ensure S3 backend pool'],
                    ['key' => 'publish_ingress', 'label' => 'Publish ingress host'],
                    ['key' => 'verify_intent', 'label' => 'Verify route intent'],
                ],
            ])
                .gatewayProgressFrame('complete', s3PublishCompleteFrame()),
        );

        [$exitCode, $output] = runCommand($this, 's3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Publishing S3 Host')
            ->and($output)
            ->toContain('Resolve S3 node')
            ->and($output)
            ->toContain('Check router and ingress')
            ->and($output)
            ->toContain('Ensure SeaweedFS credentials')
            ->and($output)
            ->toContain('Ensure private s3.orbit route')
            ->and($output)
            ->toContain('Ensure S3 backend pool')
            ->and($output)
            ->toContain('Publish ingress host')
            ->and($output)
            ->toContain('Verify route intent');
    });

    it('outputs human-readable success without --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', s3PublishCompleteFrame('s3.example.com', 'storage-1')),
        );

        $this->artisan('s3:publish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
        ])->assertSuccessful();
    });

    // -----------------------------------------------------------------------
    // Interactive input mode
    // -----------------------------------------------------------------------

    it('prompts for the host when the host argument is omitted in interactive mode', function (): void {
        fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', s3PublishCompleteFrame(
            'prompted.example.com',
            'storage-1',
        )));

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        $this
            ->artisan('s3:publish', ['--node' => 'storage-1'])
            ->expectsQuestion('Public hostname (e.g. s3.example.com)', 'prompted.example.com')
            ->assertSuccessful();

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/s3/public-hosts'
                && $request->data()['host'] === 'prompted.example.com'
            ),
        );
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
 * Build the complete frame payload as the gateway emitter sends it.
 *
 * The emitter->complete(0, $payload) produces {"exit_code":0,"data":$payload}.
 * The gateway sends: ['s3' => {...}, 'meta' => {...}] as the payload.
 *
 * @return array<string, mixed>
 */
function s3PublishCompleteFrame(string $host = 's3.example.com', string $node = 'storage-1'): array
{
    return [
        'exit_code' => 0,
        'data' => [
            's3' => [
                'node' => $node,
                'private_endpoint' => 'https://s3.orbit',
                'public_endpoints' => ["https://{$host}"],
                'backend_pool' => ["http://{$node}.s3.orbit:8333"],
                'credentials_ref' => [
                    'tool' => 'seaweedfs',
                    'node' => $node,
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
