<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Project;
use App\Services\Processes\SystemdUnitRenderer;
use App\Tools\HermesTool;
use App\Tools\OpenClawTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('escapes shell dollars so systemd does not expand process command variables', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-1',
        'user' => 'orbit',
        'status' => 'active',
    ]);
    $app = Project::factory()->for($node, 'node')->create([
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
    $app = Project::factory()->for($node, 'node')->create([
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
        ->toContain('HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="$(tr -d "\r\n" < "${PASSWORD_FILE}")"')
        ->and($execStart)
        ->not->toBeNull()->toContain('PASSWORD_FILE="/home/agent/.hermes/dashboard.password"')->toContain(
            '$${PASSWORD_FILE}',
        )->toContain('HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="$$(tr -d "\r\n" < "$${PASSWORD_FILE}")"')->toContain(
            'hermes dashboard --host 0.0.0.0 --port 8080 --no-open',
        )
        ->not->toMatch('/(?<!\$)\$\{PASSWORD_FILE\}/')
        ->not->toMatch('/(?<!\$)\$\(/');
});

it('preserves the OpenClaw gateway token shell pipeline through systemd rendering', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-1',
        'user' => 'orbit',
        'status' => 'active',
        'tld' => 'agent',
    ]);
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'agent-runtime',
        'path' => '/home/orbit',
    ]);
    $command = new OpenClawTool()->relatedProcess()['command'];
    $process = OrbitProcess::factory()
        ->forOwner($node)
        ->create([
            'name' => 'openclaw-gateway',
            'command' => $command,
            'runtime' => ProcessRuntime::Systemd,
            'restart_policy' => 'always',
            'tool' => 'openclaw',
        ]);

    $unit = app(SystemdUnitRenderer::class)->render($node, $app, $process);
    $execStart = collect(explode(PHP_EOL, $unit))
        ->first(static fn (string $line): bool => str_starts_with($line, 'ExecStart='));

    expect($command)
        ->toContain('TOKEN_FILE="/home/agent/.openclaw/gateway.token"')
        ->toContain('${TOKEN_FILE}')
        ->toContain('OPENCLAW_GATEWAY_TOKEN="$(tr -d "\r\n" < "${TOKEN_FILE}")"')
        ->and($execStart)
        ->not->toBeNull()->toContain('TOKEN_FILE="/home/agent/.openclaw/gateway.token"')->toContain(
            '$${TOKEN_FILE}',
        )->toContain('OPENCLAW_GATEWAY_TOKEN="$$(tr -d "\r\n" < "$${TOKEN_FILE}")"')->toContain(
            'openclaw gateway run --port 18789 --bind lan',
        )
        // Unescaped shell vars must not appear in the unit file payload.
        ->not->toMatch('/(?<!\$)\$\{TOKEN_FILE\}/')
        ->not->toMatch('/(?<!\$)\$\(/');
});
