<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('Manifest CLI commands', function (): void {
    it('puts the custom manifest URL to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'manifest' => manifestCommandPayload(
                url: 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
                source: 'custom',
                customUrl: 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
            ),
        ]));

        [$exitCode, $output] = runCommand($this, 'manifest:update', [
            'url' => 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PUT'
                && $request->url() === 'https://gateway.test/api/manifest'
                && $request->data() === [
                    'url' => 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['manifest']['source'])
            ->toBe('custom')
            ->and($decoded['success']['data']['manifest']['url'])
            ->toBe('https://artifacts.example.com/channels/live-test/orbit-release-manifest.json');
    });

    it('deletes the custom manifest URL from the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'manifest' => manifestCommandPayload(),
        ]));

        [$exitCode, $output] = runCommand($this, 'manifest:remove', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/manifest'
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['manifest']['source'])
            ->toBe('default')
            ->and($decoded['success']['data']['manifest']['custom_url'])
            ->toBeNull();
    });

    it('renders manifest update as concise human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'manifest' => manifestCommandPayload(
                url: 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
                source: 'custom',
                customUrl: 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
            ),
        ]));

        [$exitCode, $output] = runCommand($this, 'manifest:update', [
            'url' => 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Release manifest URL updated.')
            ->and($output)
            ->toContain('Source: custom')
            ->and($output)
            ->toContain('URL: https://artifacts.example.com/channels/live-test/orbit-release-manifest.json')
            ->and($output)
            ->not->toContain('{');
    });

    it('preserves gateway validation errors in json mode', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'Manifest URL must be an http or https URL.', [
            'field' => 'url',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 'manifest:update', [
            'url' => 'file:///tmp/orbit-release-manifest.json',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('url');
    });
});

/**
 * @return array<string, mixed>
 */
function manifestCommandPayload(
    string $url = 'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json',
    string $source = 'default',
    ?string $customUrl = null,
): array {
    return [
        'url' => $url,
        'source' => $source,
        'custom_url' => $customUrl,
        'default_url' => 'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json',
    ];
}
