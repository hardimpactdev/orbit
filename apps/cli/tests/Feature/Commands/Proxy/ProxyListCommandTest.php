<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('proxy:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'routes' => [
                [
                    'domain' => 'docs.test',
                    'kind' => 'instance',
                    'owner' => ['type' => 'instance', 'name' => 'docs.development'],
                    'node' => 'app-1',
                    'target' => ['type' => 'instance', 'value' => 'docs.development'],
                    'tls' => ['managed_by' => 'orbit'],
                    'status' => 'expected',
                ],
            ],
        ], ['filter' => 'instance', 'node' => 'app-1', 'count' => 1]));

        [$exitCode, $output] = runCommand($this, 'proxy:list', [
            '--node' => 'app-1',
            '--filter' => 'instance',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/proxy-routes')
                && str_contains($url, 'node=app-1')
                && str_contains($url, 'filter=instance')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['routes'][0]['domain'])
            ->toBe('docs.test')
            ->and($decoded['success']['meta']['filter'])
            ->toBe('instance');
    });

    it('renders human output as a table with uppercase headers and route cells', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'routes' => [
                [
                    'domain' => 'docs.test',
                    'kind' => 'instance',
                    'owner' => ['type' => 'instance', 'name' => 'docs.development'],
                    'node' => 'app-1',
                    'target' => ['type' => 'instance', 'value' => 'docs.development'],
                    'tls' => ['managed_by' => 'orbit'],
                    'status' => 'expected',
                ],
                [
                    'domain' => 'old.test',
                    'kind' => 'redirect',
                    'owner' => ['type' => 'custom', 'name' => null],
                    'node' => 'app-1',
                    'target' => ['type' => 'redirect', 'value' => 'https://new.test'],
                    'tls' => ['managed_by' => 'none'],
                    'status' => 'expected',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:list');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('DOMAIN')
            ->and($output)
            ->toContain('KIND')
            ->and($output)
            ->toContain('OWNER')
            ->and($output)
            ->toContain('NODE')
            ->and($output)
            ->toContain('TARGET')
            ->and($output)
            ->toContain('TLS')
            ->and($output)
            ->toContain('STATUS')
            ->and($output)
            ->toContain('docs.test')
            ->and($output)
            ->toContain('instance:docs.development')
            ->and($output)
            ->toContain('app-1')
            ->and($output)
            ->toContain('orbit')
            ->and($output)
            ->toContain('expected')
            ->and($output)
            ->toContain('redirect')
            ->and($output)
            ->toContain('custom')
            ->and($output)
            ->toContain('https://new.test')
            ->and($output)
            ->not->toContain('routes: [')->and($output)
            ->not->toContain('"managed_by"');
    });

    it('renders a scope-aware empty state when filtered with no matches', function (): void {
        fakeGateway(fakeSuccessEnvelope(['routes' => []], ['filter' => 'instance', 'node' => 'app-1', 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'proxy:list', [
            '--filter' => 'instance',
            '--node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0)->and($output)->toBe('No instance proxy routes found on node app-1.');
    });

    it('renders a plain empty state when unfiltered with no routes', function (): void {
        fakeGateway(fakeSuccessEnvelope(['routes' => []], ['filter' => 'all', 'node' => null, 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'proxy:list');

        expect($exitCode)->toBe(0)->and($output)->toBe('No proxy routes found.');
    });

    it('preserves the gateway validation envelope for a removed app filter', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'validation_failed',
            'The selected proxy route filter is invalid.',
            ['field' => 'filter', 'value' => 'app'],
        ), 400);

        [$exitCode, $output] = runCommand($this, 'proxy:list', [
            '--filter' => 'app',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta'])
            ->toBe(['field' => 'filter', 'value' => 'app']);
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'proxy:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
