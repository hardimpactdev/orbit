<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('tool:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                [
                    'name' => 'redis',
                    'node' => 'app-1',
                    'expected_state' => 'running',
                    'observed_state' => null,
                ],
            ],
        ], ['node' => 'app-1', 'count' => 1]));

        [$exitCode, $output] = runCommand($this, 'tool:list', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/tools')
                && str_contains($url, 'node=app-1');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['tools'][0]['name'])->toBe('redis')
            ->and($decoded['success']['meta']['count'])->toBe(1);
    });

    it('renders human output containing tool fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                [
                    'name' => 'redis',
                    'node' => 'app-1',
                    'expected_state' => 'running',
                    'observed_state' => null,
                    'version' => '7.2',
                    'managed' => true,
                    'endpoints' => [],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--node' => 'app-1']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('redis')
            ->and($output)->toContain('running')
            ->and($output)->toContain('7.2');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This node is not authorized to manage tools.'), 403);

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
