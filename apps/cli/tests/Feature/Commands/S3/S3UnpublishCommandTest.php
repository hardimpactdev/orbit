<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayStreamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('S3Unpublish CLI command', function (): void {

    // -----------------------------------------------------------------------
    // Non-interactive: request payloads
    // -----------------------------------------------------------------------

    it('sends a DELETE to the correct gateway endpoint with host in the path', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Unpublishing S3 Host',
                'steps' => [['key' => 'confirm_destructive', 'label' => 'Confirm destructive removal']],
            ])
            .gatewayProgressFrame('complete', s3UnpublishCompleteFrame()),
        );

        [$exitCode] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/s3/public-hosts/s3.example.com'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === ['node' => 'storage-1']);

        expect($exitCode)->toBe(0);
    });

    it('sends only the node when --node is given and no host in payload', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', s3UnpublishCompleteFrame()),
        );

        [$exitCode] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/s3/public-hosts/s3.example.com')
            && (! isset($request->data()['host'])));

        expect($exitCode)->toBe(0);
    });

    it('auto-resolves node from a single s3 node', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(s3UnpublishFakeNodeListEnvelope(['storage-1']), 200),
            'https://gateway.test/api/s3/public-hosts/*' => Http::response(
                gatewayProgressFrame('complete', s3UnpublishCompleteFrame()),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        [$exitCode] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--force' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/s3/public-hosts/s3.example.com'));
    });

    // -----------------------------------------------------------------------
    // Non-interactive: destructive consent
    // -----------------------------------------------------------------------

    it('fails before contacting the gateway when --force is missing in non-interactive mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force')
            ->and($decoded['error']['meta']['reason'])->toBe('destructive_consent_required');
    });

    it('fails before contacting the gateway when host is missing in non-interactive mode', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(s3UnpublishFakeNodeListEnvelope(['storage-1']), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/s3/public-hosts/'));

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('host');
    });

    it('fails before contacting the gateway when node is ambiguous in non-interactive mode', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                s3UnpublishFakeNodeListEnvelope(['storage-1', 'storage-2']),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/s3/public-hosts/'));

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node');
    });

    it('fails before contacting the gateway when no s3 nodes exist', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(s3UnpublishFakeNodeListEnvelope([]), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node')
            ->and($decoded['error']['meta']['required_role'])->toBe('s3');
    });

    // -----------------------------------------------------------------------
    // JSON output — final frame
    // -----------------------------------------------------------------------

    it('emits only the final complete frame in --json mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Unpublishing S3 Host',
                'steps' => [['key' => 'confirm_destructive', 'label' => 'Confirm destructive removal']],
            ])
            .gatewayProgressFrame('step', ['key' => 'confirm_destructive', 'status' => 'done'])
            .gatewayProgressFrame('complete', s3UnpublishCompleteFrame('s3.example.com', 'storage-1')),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and(count(array_filter(explode("\n", $output))))->toBe(1)
            ->and($output)->not->toContain('Unpublishing S3 Host')
            ->and($output)->not->toContain('Confirm destructive removal');
    });

    it('renders the success envelope fields in --json mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', s3UnpublishCompleteFrame('s3.example.com', 'storage-1')),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['data']['data']['s3']['node'])->toBe('storage-1')
            ->and($decoded['data']['data']['s3']['private_endpoint'])->toBe('https://s3.orbit')
            ->and($decoded['data']['data']['meta']['host'])->toBe('s3.example.com')
            ->and($decoded['data']['data']['meta']['action'])->toBe('unpublished')
            ->and($decoded['data']['data']['meta']['already_absent'])->toBeFalse();
    });

    it('preserves gateway error envelopes through --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'An active router role is required for S3 route cleanup.',
                'data' => [
                    'code' => 'validation_failed',
                    'message' => 'An active router role is required for S3 route cleanup.',
                    'meta' => ['field' => 'router', 'required_role' => 'router'],
                ],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['event'])->toBe('error');
    });

    it('preserves proxy.owned_route_denied error code through --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => "The host 's3.example.com' is owned by a non-S3 proxy route.",
                'data' => [
                    'code' => 'proxy.owned_route_denied',
                    'message' => "The host 's3.example.com' is owned by a non-S3 proxy route.",
                    'meta' => ['field' => 'host', 'owner_type' => 'app'],
                ],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('proxy.owned_route_denied');
    });

    it('preserves s3.unpublish_failed error code through --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Route cleanup failed.',
                'data' => [
                    'code' => 's3.unpublish_failed',
                    'message' => 'Route cleanup failed.',
                    'meta' => [],
                ],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('s3.unpublish_failed');
    });

    it('returns already_absent=true in the success frame for idempotent removal', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', s3UnpublishCompleteFrame('s3.example.com', 'storage-1', alreadyAbsent: true)),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['data']['data']['meta']['already_absent'])->toBeTrue()
            ->and($decoded['data']['data']['meta']['action'])->toBe('unpublished');
    });

    // -----------------------------------------------------------------------
    // Human output
    // -----------------------------------------------------------------------

    it('renders the progress tree in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Unpublishing S3 Host',
                'steps' => [
                    ['key' => 'confirm_destructive', 'label' => 'Confirm destructive removal'],
                    ['key' => 'resolve_node', 'label' => 'Resolve S3 node'],
                    ['key' => 'check_router', 'label' => 'Check router'],
                    ['key' => 'remove_ingress', 'label' => 'Remove ingress host'],
                    ['key' => 'remove_seaweedfs_config', 'label' => 'Remove SeaweedFS public host config'],
                    ['key' => 'apply_cleanup', 'label' => 'Apply route cleanup'],
                ],
            ])
            .gatewayProgressFrame('complete', s3UnpublishCompleteFrame()),
        );

        [$exitCode, $output] = runCommand($this, 's3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Unpublishing S3 Host')
            ->and($output)->toContain('Confirm destructive removal')
            ->and($output)->toContain('Resolve S3 node')
            ->and($output)->toContain('Check router')
            ->and($output)->toContain('Remove ingress host')
            ->and($output)->toContain('Remove SeaweedFS public host config')
            ->and($output)->toContain('Apply route cleanup');
    });

    it('outputs human-readable success without --json', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', s3UnpublishCompleteFrame('s3.example.com', 'storage-1')),
        );

        $this->artisan('s3:unpublish', [
            'host' => 's3.example.com',
            '--node' => 'storage-1',
            '--force' => true,
        ])->assertSuccessful();
    });

    // -----------------------------------------------------------------------
    // Interactive input mode
    // -----------------------------------------------------------------------

    it('prompts for the host when the host argument is omitted in interactive mode', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(s3UnpublishFakeNodeListEnvelope(['storage-1']), 200),
            'https://gateway.test/api/s3/public-hosts/*' => Http::response(
                gatewayProgressFrame('complete', s3UnpublishCompleteFrame('prompted.example.com', 'storage-1')),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        $this->artisan('s3:unpublish', ['--node' => 'storage-1', '--force' => true])
            ->expectsQuestion('Public hostname to remove (e.g. s3.example.com)', 'prompted.example.com')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/s3/public-hosts/prompted.example.com'));
    });

    it('prompts for confirmation in interactive mode without --force', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(s3UnpublishFakeNodeListEnvelope(['storage-1']), 200),
            'https://gateway.test/api/s3/public-hosts/*' => Http::response(
                gatewayProgressFrame('complete', s3UnpublishCompleteFrame('s3.example.com', 'storage-1')),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        $this->artisan('s3:unpublish', ['host' => 's3.example.com', '--node' => 'storage-1'])
            ->expectsConfirmation("Remove public S3 host 's3.example.com'?", 'yes')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/s3/public-hosts/s3.example.com'));
    });

    it('aborts removal when the interactive confirmation is rejected', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(s3UnpublishFakeNodeListEnvelope(['storage-1']), 200),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        $this->artisan('s3:unpublish', ['host' => 's3.example.com', '--node' => 'storage-1'])
            ->expectsConfirmation("Remove public S3 host 's3.example.com'?", 'no')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/s3/public-hosts/'));
    });
});

// ---------------------------------------------------------------------------
// Test helper functions
// ---------------------------------------------------------------------------

/**
 * @param  list<string>  $nodeNames
 * @return array<string, mixed>
 */
function s3UnpublishFakeNodeListEnvelope(array $nodeNames): array
{
    $nodes = array_map(fn (string $name): array => [
        'name' => $name,
        'status' => 'active',
        'roles' => [['role' => 's3', 'status' => 'active']],
    ], $nodeNames);

    return ['success' => ['data' => ['nodes' => $nodes], 'meta' => (object) []]];
}

/**
 * Build the complete frame payload as the gateway emitter sends it for s3:unpublish.
 *
 * @return array<string, mixed>
 */
function s3UnpublishCompleteFrame(
    string $host = 's3.example.com',
    string $node = 'storage-1',
    bool $alreadyAbsent = false,
): array {
    return [
        'exit_code' => 0,
        'data' => [
            's3' => [
                'node' => $node,
                'private_endpoint' => 'https://s3.orbit',
                'public_endpoints' => [],
                'backend_pool' => ["http://{$node}.s3.orbit:8333"],
            ],
            'meta' => [
                'host' => $host,
                'action' => 'unpublished',
                'already_absent' => $alreadyAbsent,
            ],
        ],
    ];
}
