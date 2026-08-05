<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Services\Processes\SystemdUnitRenderer;
use App\Tools\HermesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders only PATH and HOME for node-owned systemd processes such as node-exporter', function (): void {
    $node = Node::factory()->create([
        'name' => 'gateway',
        'user' => 'orbit',
        'status' => 'active',
        'tld' => 'gateway',
        'platform' => 'ubuntu_24-04',
    ]);
    $surrogateApp = App::factory()->for($node, 'node')->create([
        'name' => 'gateway',
        'path' => '/home/orbit',
    ]);
    $process = OrbitProcess::factory()
        ->forOwner($node)
        ->create([
            'name' => 'node-exporter',
            'command' => '/usr/local/bin/node_exporter --web.listen-address=0.0.0.0:9100',
            'runtime' => ProcessRuntime::Systemd,
            'restart_policy' => 'always',
        ]);

    $unit = app(SystemdUnitRenderer::class)->render($node, $surrogateApp, $process);

    expect($unit)
        ->toContain('Environment="PATH=')
        ->toContain('Environment="HOME=/home/orbit"')
        ->not->toContain('Environment="APP_URL=')
        ->not->toContain('Environment="VITE_APP_URL=')
        ->not->toContain('Environment="VITE_VALET_HOST=')
        ->not->toContain('Environment="VITE_DEV_SERVER_KEY=')
        ->not->toContain('Environment="VITE_DEV_SERVER_CERT=')
        ->not->toContain('https://gateway')
        ->not->toContain('gateway.gateway');
});

it('still renders Laravel Vite URL and TLS variables for app-owned systemd processes', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-dev',
        'user' => 'orbit',
        'status' => 'active',
        'tld' => 'test',
        'platform' => 'ubuntu_24-04',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
    ]);
    $process = OrbitProcess::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'runtime' => ProcessRuntime::Systemd,
            'restart_policy' => 'always',
        ]);

    $unit = app(SystemdUnitRenderer::class)->render($node, $app, $process);

    expect($unit)
        ->toContain('Environment="PATH=')
        ->toContain('Environment="HOME=/home/orbit"')
        ->toContain('Environment="APP_URL=')
        ->toContain('Environment="VITE_APP_URL=')
        ->toContain('Environment="VITE_VALET_HOST=')
        ->toContain('Environment="VITE_DEV_SERVER_KEY=')
        ->toContain('Environment="VITE_DEV_SERVER_CERT=');
});

it('escapes shell dollars so systemd does not expand process command variables', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-1',
        'user' => 'orbit',
        'status' => 'active',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'agent-runtime',
        'path' => '/home/orbit',
    ]);
    $process = OrbitProcess::factory()
        ->forOwner($node)
        ->create([
            'name' => 'shell-vars',
            'command' => 'TOKEN_FILE="${HOME}/.secret"; export TOKEN="$(cat "${TOKEN_FILE}")"; exec demo "$TOKEN"',
            'runtime' => ProcessRuntime::Systemd,
            'restart_policy' => 'always',
        ]);

    $unit = app(SystemdUnitRenderer::class)->render($node, $app, $process);
    $execStart = collect(explode(PHP_EOL, $unit))
        ->first(static fn (string $line): bool => str_starts_with($line, 'ExecStart='));

    expect($execStart)
        ->not->toBeNull()
        // systemd receives doubled dollars; after unit parsing bash still sees single $.
        ->toContain('TOKEN_FILE="$${HOME}/.secret"')->toContain('export TOKEN="$$(cat "$${TOKEN_FILE}")"')->toContain(
            'exec demo "$$TOKEN"',
        )
        ->not->toMatch('/(?<!\$)\$\{HOME\}/')
        ->not->toMatch('/(?<!\$)\$\{TOKEN_FILE\}/')
        ->not->toMatch('/(?<!\$)\$\(/');
});

it('preserves the Hermes dashboard credential shell pipeline through systemd rendering', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-1',
        'user' => 'orbit',
        'status' => 'active',
        'tld' => 'agent',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'agent-runtime',
        'path' => '/home/orbit',
    ]);
    $command = new HermesTool()->relatedProcess()['command'];
    $process = OrbitProcess::factory()
        ->forOwner($node)
        ->create([
            'name' => HermesTool::PROCESS_NAME,
            'command' => $command,
            'runtime' => ProcessRuntime::Systemd,
            'restart_policy' => 'always',
            'tool' => 'hermes',
        ]);

    $unit = app(SystemdUnitRenderer::class)->render($node, $app, $process);
    $execStart = collect(explode(PHP_EOL, $unit))
        ->first(static fn (string $line): bool => str_starts_with($line, 'ExecStart='));

    expect($command)
        ->toContain('PASSWORD_FILE="/home/agent/.hermes/dashboard.password"')
        ->toContain('${PASSWORD_FILE}')
        ->toContain('PASSWORD="$(tr -d "[:space:]" < "${PASSWORD_FILE}" 2>/dev/null || true)"')
        ->toContain('HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="${PASSWORD}"')
        ->and($execStart)
        ->not->toBeNull()->toContain('PASSWORD_FILE="/home/agent/.hermes/dashboard.password"')->toContain(
            '$${PASSWORD_FILE}',
        )->toContain('PASSWORD="$$(tr -d "[:space:]" < "$${PASSWORD_FILE}" 2>/dev/null || true)"')->toContain(
            'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="$${PASSWORD}"',
        )->toContain(
            'hermes dashboard --host 0.0.0.0 --port 8080 --no-open',
        )
        ->not->toMatch('/(?<!\$)\$\{PASSWORD_FILE\}/')
        ->not->toMatch('/(?<!\$)\$\(/');
});
