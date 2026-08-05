<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:log slug inventory resolution', function (): void {
    it('derives parent instance from unique visible workspace slug without --instance', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/workspaces') && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'workspaces' => [
                        [
                            'name' => 'feature-docs',
                            'app' => 'docs',
                            'instance' => 'development',
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/api/workspaces/feature-docs/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => true,
                    'lines' => ['unique-slug'],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['lines'])->toBe(['unique-slug']);
        Http::assertSent(
            fn (Request $request): bool => (
                str_contains(urldecode($request->url()), '/api/workspaces/feature-docs/log')
                && str_contains(urldecode($request->url()), 'instance=docs.development')
            ),
        );
    });

    it('requires --instance when the visible workspace slug is ambiguous', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/workspaces') && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'workspaces' => [
                        ['name' => 'feature-docs', 'app' => 'docs', 'instance' => 'development'],
                        ['name' => 'feature-docs', 'app' => 'docs', 'instance' => 'staging'],
                    ],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('instance')
            ->and($decoded['error']['meta']['reason'] ?? null)
            ->toBe('workspace_slug_ambiguous');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/log'));
    });

    it('resolves workspace URL hosts via proxy route instance enrichment without --instance', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'feature.docs.test',
                            'owner' => ['type' => 'workspace', 'name' => 'feature-docs'],
                            'target' => ['type' => 'workspace', 'value' => 'feature-docs'],
                            'instance' => 'docs.development',
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/api/workspaces/feature-docs/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => true,
                    'lines' => ['from-url'],
                    'target' => [
                        'type' => 'workspace',
                        'selector' => 'feature-docs',
                        'instance' => 'development',
                        'app' => 'docs',
                    ],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'https://feature.docs.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/feature-docs/log')
                && str_contains($url, 'instance=docs.development')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['lines'])
            ->toBe(['from-url']);
    });
});
