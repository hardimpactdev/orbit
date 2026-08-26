<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('adds defaults with a typed payload and forwards json', function (): void {
    fakeGateway(fakeSuccessEnvelope(['step' => ['id' => 3, 'command' => 'bun install']]));

    [$exitCode, $output] = runCommand(test: $this, command: 'app-development-setup-step:add', params: [
        'app' => 'fitta',
        '--command' => 'bun install',
        '--timeout' => '900',
        '--before' => '4',
        '--json' => true,
    ]);

    Http::assertSent(
        fn (Request $request): bool => (
            $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/fitta/development-setup-steps'
            && $request->data() === ['command' => 'bun install', 'timeout' => 900, 'before' => 4]
        ),
    );
    expect($exitCode)
        ->toBe(0)
        ->and(json_decode((string) $output, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toHaveKey('success.data.step');
});

it('resolves the app from the orbit marker when the selector is omitted', function (): void {
    $root = base_path('tests/.tmp-app-development-setup-step-marker');
    File::ensureDirectoryExists("{$root}/.orbit");
    File::put("{$root}/.orbit/config", json_encode(['instance' => 'fitta.development'], JSON_THROW_ON_ERROR));
    $previousHostCwd = getenv('ORBIT_HOST_CWD');
    putenv("ORBIT_HOST_CWD={$root}");

    try {
        fakeGateway(fakeSuccessEnvelope(['step' => ['id' => 3, 'command' => 'bun install']]));
        [$exitCode] = runCommand(test: $this, command: 'app-development-setup-step:add', params: [
            '--command' => 'bun install',
            '--json' => true,
        ]);
    } finally {
        $previousHostCwd === false ? putenv('ORBIT_HOST_CWD') : putenv("ORBIT_HOST_CWD={$previousHostCwd}");
        File::deleteDirectory($root);
    }

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/apps/fitta/development-setup-steps',
    ));
    expect($exitCode)->toBe(0);
});

it('lists defaults in a setup-step table', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'steps' => [['id' => 3, 'order' => 1, 'command' => 'bun install', 'timeout_seconds' => 600]],
    ]));

    [$exitCode, $output] = runCommand(test: $this, command: 'app-development-setup-step:list', params: [
        'app' => 'fitta',
    ]);

    expect($exitCode)
        ->toBe(0)
        ->and($output)
        ->toContain('Development setup defaults for fitta', 'bun install', 'TIMEOUT');
});

it('updates defaults and rejects empty changes before gateway io', function (): void {
    fakeGateway(fakeSuccessEnvelope(['step' => ['id' => 3, 'command' => 'bun run build']]));
    [$exitCode] = runCommand(test: $this, command: 'app-development-setup-step:update', params: [
        'app' => 'fitta',
        'step' => '3',
        '--command' => 'bun run build',
        '--json' => true,
    ]);
    Http::assertSent(
        fn (Request $request): bool => (
            $request->method() === 'PATCH'
            && $request->url() === 'https://gateway.test/api/apps/fitta/development-setup-steps/3'
            && $request->data() === ['command' => 'bun run build']
        ),
    );
    expect($exitCode)->toBe(0);

    Http::fake();
    [$exitCode, $output] = runCommand(test: $this, command: 'app-development-setup-step:update', params: [
        'app' => 'fitta',
        'step' => '3',
        '--json' => true,
    ]);
    Http::assertNothingSent();
    expect($exitCode)
        ->toBe(1)
        ->and(json_decode((string) $output, associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'])
        ->toBe('validation_failed');
});

it('removes defaults with force consent source', function (): void {
    fakeGateway(fakeSuccessEnvelope(['step' => ['id' => 3]]));
    [$exitCode] = runCommand(test: $this, command: 'app-development-setup-step:remove', params: [
        'app' => 'fitta',
        'step' => '3',
        '--force' => true,
        '--json' => true,
    ]);
    Http::assertSent(
        fn (Request $request): bool => (
            $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/apps/fitta/development-setup-steps/3'
            && $request->data() === ['destructive_consent' => true, 'destructive_consent_source' => 'force']
        ),
    );
    expect($exitCode)->toBe(0);
});

it('fails closed when interactive removal confirmation is declined', function (): void {
    fakeGateway(fakeSuccessEnvelope(['step' => ['id' => 3]]));

    $this
        ->artisan('app-development-setup-step:remove', [
            'app' => 'fitta',
            'step' => '3',
        ])
        ->expectsConfirmation(
            'Remove this app development setup default?',
            'no',
        )
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects invalid values without gateway io', function (): void {
    Http::fake();
    foreach ([
        ['app-development-setup-step:add', ['app' => 'fitta', '--command' => 'x', '--timeout' => '0']],
        ['app-development-setup-step:add', ['app' => 'fitta', '--command' => 'x', '--before' => '0']],
        [
            'app-development-setup-step:add',
            ['app' => 'fitta', '--command' => 'x', '--before' => '1', '--after' => '2'],
        ],
        ['app-development-setup-step:update', ['app' => 'fitta', 'step' => '0', '--command' => 'x']],
        ['app-development-setup-step:remove', ['app' => 'fitta', 'step' => '0', '--force' => true]],
    ] as [$command, $parameters]) {
        [$exitCode] = runCommand(test: $this, command: $command, params: [...$parameters, '--json' => true]);
        expect($exitCode)->toBe(1);
    }
    Http::assertNothingSent();
});
