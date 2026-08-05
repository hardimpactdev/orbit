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
                'instance' => 'development',
                'phase' => 'setup',
                'order' => 1,
                'command' => 'composer install',
                'timeout_seconds' => 900,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--instance' => 'docs',
            '--command' => 'composer install',
            '--timeout' => '900',
            '--before' => '12',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/workspaces/steps/setup'
                && $request->data() === [
                    'instance' => 'docs',
                    'command' => 'composer install',
                    'timeout' => 900,
                    'before' => 12,
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['step']['phase'])->toBe('setup');
    });

    it('posts workspace-teardown-step:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'added'],
            'step' => [
                'id' => 11,
                'app' => 'docs',
                'instance' => 'development',
                'phase' => 'teardown',
                'order' => 1,
                'command' => 'dropdb docs',
                'timeout_seconds' => 600,
            ],
        ]));

        [$exitCode] = runCommand($this, 'workspace-teardown-step:add', [
            '--instance' => 'docs',
            '--command' => 'dropdb docs',
            '--after' => '10',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/workspaces/steps/teardown'
                && $request->data() === [
                    'instance' => 'docs',
                    'command' => 'dropdb docs',
                    'timeout' => 600,
                    'after' => 10,
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('renders workspace-setup-step:add human output as prose with step detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'added'],
            'step' => [
                'id' => 10,
                'app' => 'docs',
                'instance' => 'development',
                'phase' => 'setup',
                'order' => 2,
                'command' => 'composer install',
                'timeout_seconds' => 900,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--instance' => 'docs',
            '--command' => 'composer install',
            '--timeout' => '900',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Setup step added for instance 'docs.development'.")
            ->and($output)
            ->toContain('ID: 10')
            ->and($output)
            ->toContain('Command: composer install')
            ->and($output)
            ->toContain('Order: 2')
            ->and($output)
            ->toContain('Timeout: 900 seconds')
            ->and($output)
            ->not->toContain('result:')->and($output)
            ->not->toContain('{');
    });

    it('renders workspace-teardown-step:add human output with the teardown label', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'added'],
            'step' => [
                'id' => 11,
                'app' => 'docs',
                'instance' => 'development',
                'phase' => 'teardown',
                'order' => 1,
                'command' => 'dropdb docs',
                'timeout_seconds' => 600,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-teardown-step:add', [
            '--instance' => 'docs',
            '--command' => 'dropdb docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Teardown step added for instance 'docs.development'.")
            ->and($output)
            ->toContain('ID: 11')
            ->and($output)
            ->toContain('Command: dropdb docs')
            ->and($output)
            ->toContain('Order: 1')
            ->and($output)
            ->toContain('Timeout: 600 seconds')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders workspace step add gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'workspace.step_not_found',
            "Referenced insertion step '99' not found for app 'docs' in phase 'setup'.",
            ['id' => 99, 'app' => 'docs', 'phase' => 'setup'],
        ), 404);

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--instance' => 'docs',
            '--command' => 'composer install',
            '--before' => '99',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain("Referenced insertion step '99' not found")
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders app-instance-required gateway failures for bare project selectors', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'validation_failed',
            'Workspace steps can only be changed on instances. Use a dotted selector such as hauser.nmbp.',
            ['field' => 'instance', 'reason' => 'instance_required'],
        ), 400);

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--instance' => 'docs',
            '--command' => 'composer install',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('dotted selector such as hauser.nmbp')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders step-not-found gateway failures when another instance step is used as an anchor', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'workspace.step_not_found',
            "Referenced insertion step '7' not found for app 'hauser' in phase 'setup'.",
            ['id' => 7, 'app' => 'hauser', 'phase' => 'setup'],
        ), 404);

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--instance' => 'hauser.nmbp',
            '--command' => 'composer install',
            '--before' => '7',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain("Referenced insertion step '7' not found")
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders app-instance-required gateway failures for bare project selectors on remove', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'validation_failed',
            'Workspace steps can only be changed on instances. Use a dotted selector such as hauser.nmbp.',
            ['field' => 'instance', 'reason' => 'instance_required'],
        ), 400);

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('dotted selector such as hauser.nmbp')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('rejects conflicting insertion anchors before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            '--instance' => 'docs',
            '--command' => 'composer install',
            '--before' => '12',
            '--after' => '13',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('workspace.invalid_position');
    });

    it('validates workspace step add inputs before contacting the gateway', function (
        array $params,
        string $field,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:add', [
            ...$params,
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe($field);
    })->with([
        'command' => [[], 'command'],
        'timeout' => [['--command' => 'composer install', '--timeout' => '0'], 'timeout'],
        'before' => [['--command' => 'composer install', '--before' => 'zero'], 'before'],
        'after' => [['--command' => 'composer install', '--after' => '-1'], 'after'],
    ]);

    it('validates workspace step removal input before contacting the gateway', function (
        string $command,
        array $params,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            ...$params,
            '--instance' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('step');
    })->with([
        'setup missing step' => ['workspace-setup-step:remove', []],
        'teardown invalid step' => ['workspace-teardown-step:remove', ['--step' => 'zero']],
    ]);

    it('requires force before removing a workspace step non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force');
    });

    it('deletes workspace setup steps with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 12, 'app' => 'docs', 'instance' => 'development', 'phase' => 'setup'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--instance' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/setup/12?instance=docs'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            );
        });

        expect($exitCode)->toBe(0);
    });

    it('deletes workspace teardown steps with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 14, 'app' => 'docs', 'instance' => 'development', 'phase' => 'teardown'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'workspace-teardown-step:remove', [
            '--step' => '14',
            '--instance' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/teardown/14?instance=docs'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            );
        });

        expect($exitCode)->toBe(0);
    });

    it('renders workspace-setup-step:remove human output as prose with the renumber hint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 12, 'app' => 'docs', 'instance' => 'development', 'phase' => 'setup'],
        ], [
            'remaining_step_count' => 2,
            'new_step_count' => 2,
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("✓ Removed setup step 12 from instance 'docs.development'.")
            ->and($output)
            ->toContain('Remaining steps renumbered.')
            ->and($output)
            ->not->toContain('no workspace setup steps')->and($output)
            ->not->toContain('result:')->and($output)
            ->not->toContain('{');
    });

    it('renders workspace-setup-step:remove empty-list hint when the last step is removed', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 12, 'app' => 'docs', 'instance' => 'development', 'phase' => 'setup'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '12',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("✓ Removed setup step 12 from instance 'docs.development'.")
            ->and($output)
            ->toContain("Instance 'docs.development' now has no workspace setup steps.")
            ->and($output)
            ->not->toContain('Remaining steps renumbered.');
    });

    it('renders workspace-teardown-step:remove human output with the teardown label', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 14, 'app' => 'docs', 'instance' => 'development', 'phase' => 'teardown'],
        ], [
            'remaining_step_count' => 1,
            'new_step_count' => 1,
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-teardown-step:remove', [
            '--step' => '14',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("✓ Removed teardown step 14 from instance 'docs.development'.")
            ->and($output)
            ->toContain('Remaining steps renumbered.')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders workspace step removal gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'workspace.step_not_found',
            "Setup step '99' not found for app 'docs' in phase 'setup'.",
            ['step_id' => 99, 'app' => 'docs', 'phase' => 'setup'],
        ), 404);

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:remove', [
            '--step' => '99',
            '--instance' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain("Setup step '99' not found")
            ->and($output)
            ->not->toContain('"error"');
    });

    it('prompts for setup step ids before confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 12, 'app' => 'docs', 'instance' => 'development', 'phase' => 'setup'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        $this
            ->artisan('workspace-setup-step:remove', ['--instance' => 'docs'])
            ->expectsQuestion('Step ID', '12')
            ->expectsConfirmation('Remove this workspace step?', 'yes')
            ->expectsOutputToContain("Removed setup step 12 from instance 'docs.development'.")
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/setup/12?instance=docs'
            );
        });
    });

    it('prompts for teardown step ids before confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => ['id' => 14, 'app' => 'docs', 'instance' => 'development', 'phase' => 'teardown'],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        $this
            ->artisan('workspace-teardown-step:remove', ['--instance' => 'docs'])
            ->expectsQuestion('Step ID', '14')
            ->expectsConfirmation('Remove this workspace step?', 'yes')
            ->expectsOutputToContain("Removed teardown step 14 from instance 'docs.development'.")
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/steps/teardown/14?instance=docs'
            );
        });
    });
});
