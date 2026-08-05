<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->cloudflareConfigPath = orbit_test_config_path(prefix: 'orbit-cloudflare-render-');
    unlink_orbit_test_file($this->cloudflareConfigPath);

    $store = new OrbitConfigStore(overridePath: $this->cloudflareConfigPath);
    $store->enableExtension('cloudflare');

    app()->instance(OrbitConfigStore::class, $store);
});

afterEach(function (): void {
    unlink_orbit_test_file($this->cloudflareConfigPath);
});

describe('Cloudflare human render commands', function (): void {
    it('renders cf-dns:add as a progress tree with a created success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'record' => [
                'id' => 'record-new',
                'zone' => 'example.com',
                'name' => 'docs.example.com',
                'content' => '203.0.113.10',
                'type' => 'A',
                'proxied' => true,
                'status' => 'created',
            ],
        ], [
            'action' => 'create',
            'already_present' => false,
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:add', params: [
            'name' => 'docs.example.com',
            'content' => '203.0.113.10',
            '--zone' => 'example.com',
            '--proxied' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Adding Cloudflare DNS record')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Check existing address records')
            ->and($output)
            ->toContain('Write Cloudflare DNS record')
            ->and($output)
            ->toContain('Cloudflare DNS record docs.example.com A -> 203.0.113.10 created')
            ->and($output)
            ->not->toContain('record:')->and($output)
            ->not->toContain('{');
    });

    it('renders cf-dns:add already-present output when the record exists', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'record' => [
                'id' => 'record-old',
                'zone' => 'example.com',
                'name' => 'docs.example.com',
                'content' => '203.0.113.10',
                'type' => 'A',
                'proxied' => true,
                'status' => 'observed',
            ],
        ], [
            'action' => 'create',
            'already_present' => true,
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:add', params: [
            'name' => 'docs.example.com',
            'content' => '203.0.113.10',
            '--zone' => 'example.com',
            '--proxied' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Cloudflare DNS record docs.example.com A -> 203.0.113.10 already present')
            ->and($output)
            ->not->toContain('created');
    });

    it('renders cf-dns:add gateway failures as prose without an envelope', function (): void {
        fakeGateway(fakeErrorEnvelope('cloudflare_unavailable', 'Cloudflare token is missing.'), 503);

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:add', params: [
            'name' => 'docs.example.com',
            'content' => '203.0.113.10',
            '--zone' => 'example.com',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('Cloudflare token is missing.')
            ->and($output)
            ->not->toContain('"error"')->and($output)
            ->not->toContain('cloudflare_unavailable:');
    });

    it('renders cf-dns:remove as a progress tree with a removed success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'record' => [
                'id' => 'record-1',
                'zone' => 'example.com',
                'name' => 'docs.example.com',
                'content' => '203.0.113.10',
                'type' => 'A',
                'proxied' => false,
                'status' => 'removed',
            ],
        ], [
            'action' => 'remove',
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:remove', params: [
            'record-id' => 'record-1',
            '--zone' => 'example.com',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Removing Cloudflare DNS record')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Verify DNS record')
            ->and($output)
            ->toContain('Delete Cloudflare DNS record')
            ->and($output)
            ->toContain('Cloudflare DNS record record-1 removed from example.com')
            ->and($output)
            ->not->toContain('{');
    });

    it('requires --force before cf-dns:remove without rendering a tree', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:remove', params: [
            'record-id' => 'record-1',
            '--zone' => 'example.com',
        ]);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->not->toContain('Removing Cloudflare DNS record');
    });

    it('renders cf-cache:flush as a progress tree with a flushed success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'cache' => [
                'zone' => 'example.com',
                'app' => null,
                'action' => 'flush',
                'status' => 'flushed',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-cache:flush', params: [
            '--zone' => 'example.com',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Flushing Cloudflare cache')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Request cache purge')
            ->and($output)
            ->toContain('Cloudflare cache flushed for example.com')
            ->and($output)
            ->not->toContain('cache:')->and($output)
            ->not->toContain('{');
    });

    it('renders cf-cache-rule:add as a progress tree with a ready success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'app' => 'docs',
                'zone' => 'example.com',
                'action' => 'add',
                'cache' => true,
                'browser_ttl' => 'respect_origin',
                'status' => 'ready',
            ],
        ], [
            'provider' => 'cloudflare',
            'already_present' => false,
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-cache-rule:add', params: [
            'app' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Adding Cloudflare cache rule')
            ->and($output)
            ->toContain('Resolve project domain')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Write cache rule')
            ->and($output)
            ->toContain('Cloudflare cache rule ready for docs: respect origin Cache-Control')
            ->and($output)
            ->not->toContain('rule:')->and($output)
            ->not->toContain('{');
    });

    it('renders cf-cache-rule:remove as a progress tree with a removed success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'app' => 'docs',
                'zone' => 'example.com',
                'action' => 'remove',
                'status' => 'removed',
            ],
        ], [
            'provider' => 'cloudflare',
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-cache-rule:remove', params: [
            'app' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Removing Cloudflare cache rule')
            ->and($output)
            ->toContain('Resolve project domain')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Delete cache rule')
            ->and($output)
            ->toContain('Cloudflare cache rule removed for docs')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders cf-ssl:enable as a progress tree with the SSL mode success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'ssl' => [
                'zone' => 'example.com',
                'mode' => 'strict',
                'action' => 'enable',
                'status' => 'set',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-ssl:enable', params: [
            'zone' => 'example.com',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Enabling Cloudflare SSL')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Validate SSL mode')
            ->and($output)
            ->toContain('Update Cloudflare SSL mode')
            ->and($output)
            ->toContain('Cloudflare SSL mode for example.com set to strict')
            ->and($output)
            ->not->toContain('ssl:')->and($output)
            ->not->toContain('{');
    });

    it('renders cf-ssl:disable as a progress tree with a disabled success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'ssl' => [
                'zone' => 'example.com',
                'mode' => 'off',
                'action' => 'disable',
                'status' => 'set',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-ssl:disable', params: [
            'zone' => 'example.com',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Disabling Cloudflare SSL')
            ->and($output)
            ->toContain('Resolve Cloudflare zone')
            ->and($output)
            ->toContain('Confirm destructive SSL change')
            ->and($output)
            ->toContain('Update Cloudflare SSL mode')
            ->and($output)
            ->toContain('Cloudflare SSL disabled for example.com')
            ->and($output)
            ->not->toContain('{');
    });

    it('still emits JSON envelopes for Cloudflare writes when --json is present', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'ssl' => [
                'zone' => 'example.com',
                'mode' => 'strict',
                'action' => 'enable',
                'status' => 'set',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'cf-ssl:enable', params: [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['ssl']['mode'])
            ->toBe('strict')
            ->and($output)
            ->not->toContain('Enabling Cloudflare SSL');
    });
});
