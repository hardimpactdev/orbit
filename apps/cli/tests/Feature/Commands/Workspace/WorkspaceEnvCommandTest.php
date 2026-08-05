<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:env', function (): void {
    it('sets and applies a workspace env value through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'scope' => 'workspace',
            'app' => 'billing',
            'instance' => 'development',
            'workspace' => 'feature-mail',
            'path' => '/home/orbit/apps/billing/.worktrees/feature-mail/.env',
            'stored' => true,
            'applied' => true,
            'runtime_restarted' => true,
            'variable' => [
                'key' => 'MAIL_MAILER',
                'value' => 'smtp',
                'secret' => false,
            ],
        ]));

        [$exitCode, $output] = runCommand(test: $this, command: 'workspace:env', params: [
            'action' => 'set',
            'name' => 'feature-mail',
            '--instance' => 'billing.development',
            '--key' => 'MAIL_MAILER',
            '--value' => 'smtp',
            '--apply' => true,
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url()
                === 'https://gateway.test/api/workspaces/feature-mail/env?instance=billing.development'
                && $request->data() === [
                    'key' => 'MAIL_MAILER',
                    'value' => 'smtp',
                    'apply' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['success']['data']['scope'])
            ->toBe('workspace');
    });

    it('renders human workspace target metadata when no explicit values exist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'scope' => 'workspace',
            'app' => 'billing',
            'instance' => 'development',
            'workspace' => 'feature-mail',
            'path' => '/home/orbit/apps/billing/.worktrees/feature-mail/.env',
            'stored' => false,
            'applied' => false,
            'runtime_restarted' => false,
            'variables' => [],
        ]));

        [$exitCode, $output] = runCommand(test: $this, command: 'workspace:env', params: [
            'action' => 'list',
            'name' => 'feature-mail',
            '--instance' => 'billing.development',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('No environment values found.')
            ->and($output)
            ->toContain('Scope: workspace')
            ->and($output)
            ->toContain('App: billing')
            ->and($output)
            ->toContain('Instance: development')
            ->and($output)
            ->toContain('Workspace: feature-mail')
            ->and($output)
            ->toContain('Path: /home/orbit/apps/billing/.worktrees/feature-mail/.env')
            ->and($output)
            ->toContain('Stored: no')
            ->and($output)
            ->toContain('Applied: no')
            ->and($output)
            ->toContain('Runtime restarted: no');
    });

    it('resolves a workspace from host cwd when name is omitted', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        Http::fake([
            'https://gateway.test/*' => Http::sequence()
                ->push(fakeSuccessEnvelope([
                    'app' => 'billing',
                    'instance' => 'development',
                    'workspace' => 'feature-mail',
                ]))
                ->push(fakeSuccessEnvelope([
                    'scope' => 'workspace',
                    'app' => 'billing',
                    'instance' => 'development',
                    'workspace' => 'feature-mail',
                    'path' => '/worktrees/feature-mail/.env',
                    'stored' => false,
                    'applied' => false,
                    'runtime_restarted' => false,
                    'variables' => [],
                ])),
        ]);

        $previous = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/worktrees/feature-mail/subdir');

        try {
            [$exitCode] = runCommand(test: $this, command: 'workspace:env', params: [
                'action' => 'list',
                '--json' => true,
            ]);
        } finally {
            $previous === false
                ? putenv('ORBIT_HOST_CWD')
                : putenv("ORBIT_HOST_CWD={$previous}");
        }

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with(
                $request->url(),
                'https://gateway.test/api/workspaces/env/resolve-by-path?path=',
            ),
        );
        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url()
                === 'https://gateway.test/api/workspaces/feature-mail/env?instance=billing.development'
            ),
        );

        expect($exitCode)->toBe(0);
    });
});
