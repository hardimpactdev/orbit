<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('firewall:list', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rules' => [
                [
                    'name' => 'local-vite',
                    'node' => 'app-1',
                    'direction' => 'incoming',
                    'action' => 'allow',
                    'source' => '10.6.0.0/24',
                    'destination' => null,
                    'port' => 5173,
                    'protocol' => 'tcp',
                    'reason' => 'local development server',
                    'status' => 'expected',
                ],
            ],
        ], [
            'node' => null,
            'count' => 1,
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/firewall-rules'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['rules'][0]['name'])->toBe('local-vite')
            ->and($decoded['success']['meta'])->toMatchArray([
                'node' => null,
                'count' => 1,
            ]);
    });

    it('forwards the optional node filter', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rules' => [
                [
                    'name' => 'local-vite',
                    'node' => 'app-1',
                    'direction' => 'incoming',
                    'action' => 'allow',
                ],
            ],
        ], [
            'node' => 'app-1',
            'count' => 1,
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:list', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/firewall-rules')
                && str_contains($url, 'node=app-1');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['meta']['node'])->toBe('app-1');
    });

    it('renders human output grouped by node as tables with uppercase headers', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rules' => [
                [
                    'name' => 'local-vite',
                    'node' => 'app-1',
                    'direction' => 'incoming',
                    'action' => 'allow',
                    'source' => '10.6.0.0/24',
                    'destination' => null,
                    'port' => 5173,
                    'protocol' => 'tcp',
                    'status' => 'expected',
                ],
                [
                    'name' => 'block-ssh',
                    'node' => 'app-2',
                    'direction' => 'incoming',
                    'action' => 'deny',
                    'source' => 'any',
                    'destination' => null,
                    'port' => 22,
                    'protocol' => 'tcp',
                    'status' => 'enacted',
                ],
            ],
        ], [
            'node' => null,
            'count' => 2,
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('Node: app-2')
            ->and($output)->toContain('NAME')
            ->and($output)->toContain('DIRECTION')
            ->and($output)->toContain('ACTION')
            ->and($output)->toContain('SOURCE')
            ->and($output)->toContain('DESTINATION')
            ->and($output)->toContain('PORT')
            ->and($output)->toContain('PROTOCOL')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('local-vite')
            ->and($output)->toContain('allow')
            ->and($output)->toContain('10.6.0.0/24')
            ->and($output)->toContain('5173')
            ->and($output)->toContain('block-ssh')
            ->and($output)->toContain('deny')
            ->and($output)->toContain('—')
            ->and($output)->not->toContain('rules: [')
            ->and($output)->not->toContain('"protocol"');
    });

    it('renders a scope-aware empty state when filtered with no matches', function (): void {
        fakeGateway(fakeSuccessEnvelope(['rules' => []], ['node' => 'app-1', 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'firewall:list', ['--node' => 'app-1']);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No firewall rules found on node app-1.');
    });

    it('renders a plain empty state when unfiltered with no rules', function (): void {
        fakeGateway(fakeSuccessEnvelope(['rules' => []], ['node' => null, 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'firewall:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No firewall rules found.');
    });

    it('rejects invalid node input before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['rules' => []]));

        [$exitCode, $output] = runCommand($this, 'firewall:list', [
            '--node' => 'one,two',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta'])->toMatchArray([
                'field' => 'node',
                'node' => 'one,two',
            ]);
    });

    it('passes through authorization failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing firewall read permission.', [
            'missing_permission' => 'firewall_rule:read',
        ]), 403);

        [$exitCode, $output] = runCommand($this, 'firewall:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('firewall_rule:read');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'firewall:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
