<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace step mutation commands', function (): void {
    it('posts workspace-setup-step:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'added'],
            'step' => [
                'id' => 10,
                'app' => 'docs',
                'phase' => 'setup',
                'order' => 1,
                'command' => 'composer install',
                'timeout_seconds' => 900,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'composer install',
            '--timeout' => '900',
            '--before' => '12',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/workspaces/steps/setup'
            && $request->data() === [
                'app' => 'docs',
                'command' => 'composer install',
                'timeout' => 900,
                'before' => 12,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['step']['phase'])->toBe('setup');
    });

    it('posts workspace-teardown-step:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'added'],
            'step' => [
                'id' => 11,
                'app' => 'docs',
                'phase' => 'teardown',
                'order' => 1,
                'command' => 'dropdb docs',
                'timeout_seconds' => 600,
            ],
        ]));

        [$exitCode] = runCommand($this, 'workspace-teardown-step:add', [
            '--app' => 'docs',
            '--command' => 'dropdb docs',
            '--after' => '10',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/workspaces/steps/teardown'
            && $request->data() === [
                'app' => 'docs',
                'command' => 'dropdb docs',
                'timeout' => 600,
                'after' => 10,
            ]);

        expect($exitCode)->toBe(0);
    });

    it('rejects conflicting insertion anchors before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'composer install',
            '--before' => '12',
            '--after' => '13',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('workspace.invalid_position');
    });

    it('validates workspace step add inputs before contacting the gateway', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            ...$params,
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'command' => [[], 'command'],
        'timeout' => [['--command' => 'composer install', '--timeout' => '0'], 'timeout'],
        'before' => [['--command' => 'composer install', '--before' => 'zero'], 'before'],
        'after' => [['--command' => 'composer install', '--after' => '-1'], 'after'],
    ]);

    it('validates workspace step removal input before contacting the gateway', function (string $command, array $params): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            ...$params,
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('step');
    })->with([
        'setup missing step' => ['workspace-setup-step:remove', []],
        'teardown invalid step' => ['workspace-teardown-step:remove', ['--step' => 'zero']],
    ]);

    it('requires force before removing a workspace step non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes workspace setup steps with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 12, 'app' => 'docs', 'phase' => 'setup'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/setup/12?app=docs'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ];
        });

        expect($exitCode)->toBe(0);
    });

    it('deletes workspace teardown steps with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 14, 'app' => 'docs', 'phase' => 'teardown'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'workspace-teardown-step:remove', [
            '--step' => '14',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/teardown/14?app=docs'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ];
        });

        expect($exitCode)->toBe(0);
    });

    it('prompts for setup step ids before confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 12, 'app' => 'docs', 'phase' => 'setup'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        $this->artisan('workspace-setup-step:remove', ['--app' => 'docs'])
            ->expectsQuestion('Step ID', '12')
            ->expectsConfirmation('Remove this workspace step?', 'yes')
            ->expectsOutputToContain('result')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/setup/12?app=docs';
        });
    });

    it('prompts for teardown step ids before confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 14, 'app' => 'docs', 'phase' => 'teardown'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        $this->artisan('workspace-teardown-step:remove', ['--app' => 'docs'])
            ->expectsQuestion('Step ID', '14')
            ->expectsConfirmation('Remove this workspace step?', 'yes')
            ->expectsOutputToContain('result')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/teardown/14?app=docs';
        });
    });
});
