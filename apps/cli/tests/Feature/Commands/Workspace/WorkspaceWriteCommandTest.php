<?php

declare(strict_types=1);

use App\Services\GatewayStreamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace write commands', function (): void {
    it('posts workspace:new payloads to the gateway workspaces endpoint', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayStreamClient::class);

        Http::fake([
            'https://gateway.test/*' => Http::response(
                "event: complete\n"
                .'data: {"exit_code":0,"data":{"result":{"result":{"action":"created"},"workspace":{"name":"feature-docs","app":"docs"}}}}'."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        [$exitCode, $output] = runCommand($this, 'workspace:new', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--base' => 'main',
            '--php-version' => '8.5',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/workspaces'
            && $request->data() === [
                'name' => 'feature-docs',
                'app' => 'docs',
                'base' => 'main',
                'php_version' => '8.5',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data']['data']['result']['result']['action'])->toBe('created');
    });

    it('validates workspace:new names before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace:new', [
            'name' => 'main',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('posts workspace:setup payloads with caller cwd context', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/Users/nckrtl/Sites/docs/.worktrees/feature-docs');

        try {
            config()->set('orbit.gateway.url', 'https://gateway.test');
            config()->set('orbit.gateway.timeout', 30);
            app()->forgetInstance(GatewayStreamClient::class);

            Http::fake([
                'https://gateway.test/*' => Http::response(
                    "event: complete\n"
                    .'data: {"exit_code":0,"data":{"result":{"workspace":"feature-docs","app":"docs","action":"set_up"}}}'."\n\n",
                    200,
                    ['Content-Type' => 'text/event-stream'],
                ),
            ]);

            [$exitCode] = runCommand($this, 'workspace:setup', [
                'name' => 'feature-docs',
                '--app' => 'docs',
                '--path' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
                '--json' => true,
            ]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/workspaces/setup'
            && $request->data() === [
                'name' => 'feature-docs',
                'app' => 'docs',
                'path' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
                'caller_cwd' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
            ]);

        expect($exitCode)->toBe(0);
    });

    it('requires force before removing a workspace non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes workspace:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'feature-docs',
            'app' => 'docs',
            'action' => 'removed',
        ], [
            'kept_files' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--keep-files' => true,
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/feature-docs?app=docs'
                && $request->data() === [
                    'keep_files' => true,
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['action'])->toBe('removed');
    });

    it('prompts for workspace:remove name and confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'feature-docs',
            'app' => 'docs',
            'action' => 'removed',
        ]));

        $this->artisan('workspace:remove', ['--app' => 'docs'])
            ->expectsQuestion('Workspace name', 'feature-docs')
            ->expectsConfirmation("Remove workspace 'feature-docs'?", 'yes')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/feature-docs?app=docs'
                && $request->data() === [
                    'keep_files' => false,
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ];
        });
    });

    it('validates workspace:setup paths before opening a gateway stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--path' => 'relative/path',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('path');
    });

});
