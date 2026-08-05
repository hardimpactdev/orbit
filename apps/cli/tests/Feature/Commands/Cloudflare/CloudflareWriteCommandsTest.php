<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->cloudflareConfigPath = orbit_test_config_path(prefix: 'orbit-cloudflare-write-');
    unlink_orbit_test_file($this->cloudflareConfigPath);

    $store = new OrbitConfigStore(overridePath: $this->cloudflareConfigPath);
    $store->enableExtension('cloudflare');

    app()->instance(OrbitConfigStore::class, $store);
});

afterEach(function (): void {
    unlink_orbit_test_file($this->cloudflareConfigPath);
});

describe('Cloudflare write commands', function (): void {
    it('posts cf-dns:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'record' => [
                'id' => 'record-new',
                'zone' => 'lindaretel.nl',
                'name' => 'docs.lindaretel.nl',
                'content' => '203.0.113.10',
                'type' => 'A',
                'proxied' => true,
                'status' => 'created',
            ],
        ], [
            'action' => 'create',
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:add', params: [
            'name' => 'docs.lindaretel.nl',
            'content' => '203.0.113.10',
            '--zone' => 'lindaretel.nl',
            '--proxied' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/cloudflare/zones/lindaretel.nl/dns'
                && $request->data() === [
                    'name' => 'docs.lindaretel.nl',
                    'content' => '203.0.113.10',
                    'type' => 'A',
                    'proxied' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['record']['status'])
            ->toBe('created')
            ->and($decoded['success']['meta']['action'])
            ->toBe('create');
    });

    it('validates cf-dns:add required input before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$missingNameExitCode, $missingNameOutput] = runCommand($this, command: 'cf-dns:add', params: [
            '--json' => true,
        ]);

        [$missingContentExitCode, $missingContentOutput] = runCommand($this, command: 'cf-dns:add', params: [
            'name' => 'docs.lindaretel.nl',
            '--json' => true,
        ]);

        $missingName = json_decode($missingNameOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $missingContent = json_decode($missingContentOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($missingNameExitCode)
            ->toBe(1)
            ->and($missingName['error']['code'])
            ->toBe('validation_failed')
            ->and($missingName['error']['meta']['field'])
            ->toBe('name')
            ->and($missingContentExitCode)
            ->toBe(1)
            ->and($missingContent['error']['code'])
            ->toBe('validation_failed')
            ->and($missingContent['error']['meta']['field'])
            ->toBe('content');
    });

    it('deletes cf-dns:remove targets only with destructive consent', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'record' => [
                'id' => 'record-1',
                'zone' => 'lindaretel.nl',
                'status' => 'removed',
            ],
        ], [
            'action' => 'remove',
        ]));

        [$missingConsentExitCode, $missingConsentOutput] = runCommand($this, command: 'cf-dns:remove', params: [
            'record-id' => 'record-1',
            '--zone' => 'lindaretel.nl',
            '--json' => true,
        ]);

        $missingConsent = json_decode($missingConsentOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($missingConsentExitCode)
            ->toBe(1)
            ->and($missingConsent['error']['code'])
            ->toBe('validation_failed')
            ->and($missingConsent['error']['meta'])
            ->toMatchArray([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]);

        fakeGateway(fakeSuccessEnvelope([
            'record' => [
                'id' => 'record-1',
                'zone' => 'lindaretel.nl',
                'status' => 'removed',
            ],
        ], [
            'action' => 'remove',
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:remove', params: [
            'record-id' => 'record-1',
            '--zone' => 'lindaretel.nl',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/cloudflare/zones/lindaretel.nl/dns/record-1'
                && $request->data() === ['destructive_consent' => true]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['record']['status'])->toBe('removed');
    });

    it('prompts for the cf-cache:flush zone in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'cache' => [
                'zone' => 'lindaretel.nl',
                'action' => 'flush',
                'status' => 'flushed',
            ],
        ]));

        $this
            ->artisan('cf-cache:flush')
            ->expectsQuestion('Cloudflare zone', 'lindaretel.nl')
            ->expectsOutputToContain('cache')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/cloudflare/cache/flush'
                && $request->data() === ['zone' => 'lindaretel.nl']
            ),
        );
    });

    it('validates cf-cache:flush zone before contacting the gateway in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, command: 'cf-cache:flush', params: ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('zone');
    });

    it('posts cache rule and ssl writes to their gateway endpoints', function (
        string $command,
        array $arguments,
        string $method,
        string $url,
        array $expectedPayload,
        string $dataKey,
    ): void {
        fakeGateway(fakeSuccessEnvelope([
            $dataKey => [
                'action' => $command,
                'status' => 'done',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            ...$arguments,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === $method
                && $request->url() === $url
                && $request->data() === $expectedPayload
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data'][$dataKey]['status'])->toBe('done');
    })->with([
        'cf-cache-rule:add' => [
            'cf-cache-rule:add',
            ['app' => 'docs'],
            'POST',
            'https://gateway.test/api/cloudflare/cache-rules/docs',
            [],
            'rule',
        ],
        'cf-cache-rule:remove' => [
            'cf-cache-rule:remove',
            ['app' => 'docs', '--force' => true],
            'DELETE',
            'https://gateway.test/api/cloudflare/cache-rules/docs',
            ['destructive_consent' => true],
            'rule',
        ],
        'cf-ssl:enable' => [
            'cf-ssl:enable',
            ['zone' => 'lindaretel.nl', '--mode' => 'full'],
            'PUT',
            'https://gateway.test/api/cloudflare/zones/lindaretel.nl/ssl',
            ['mode' => 'full'],
            'ssl',
        ],
        'cf-ssl:disable' => [
            'cf-ssl:disable',
            ['zone' => 'lindaretel.nl', '--force' => true],
            'PUT',
            'https://gateway.test/api/cloudflare/zones/lindaretel.nl/ssl/disable',
            ['destructive_consent' => true],
            'ssl',
        ],
    ]);

    it('requires force for destructive Cloudflare writes in JSON mode', function (
        string $command,
        array $arguments,
    ): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, $command, [
            ...$arguments,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta'])
            ->toMatchArray([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]);
    })->with([
        'cf-cache-rule:remove' => ['cf-cache-rule:remove', ['app' => 'docs']],
        'cf-ssl:disable' => ['cf-ssl:disable', ['zone' => 'lindaretel.nl']],
    ]);

    it('preserves gateway error envelopes for Cloudflare writes', function (): void {
        fakeGateway(fakeErrorEnvelope('cloudflare_unavailable', 'Cloudflare token is missing.', [
            'reason' => 'token_missing',
        ]), 503);

        [$exitCode, $output] = runCommand($this, command: 'cf-cache:flush', params: [
            '--zone' => 'lindaretel.nl',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('cloudflare_unavailable')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('token_missing');
    });
});
