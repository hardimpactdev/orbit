<?php

declare(strict_types=1);

use App\Commands\AgentIde\AgentIdeMessageCommand;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @return array<string, mixed>
 */
function agentIdeMessageSuccess(string $adapter = 'opencode', ?string $workspace = null, string $input = 'argument'): array
{
    return fakeSuccessEnvelope([
        'agent_ide' => [
            'adapter' => $adapter,
            'source' => $workspace === null ? 'app' : 'workspace',
            'target' => [
                'app' => 'docs',
                'workspace' => $workspace,
                'node' => 'app-1',
            ],
            'session' => [
                'id' => $workspace === null ? 'sess_app' : 'sess_workspace',
                'status' => 'active',
            ],
            'delivery' => [
                'status' => 'sent',
                'message_bytes' => 13,
                'input' => $input,
            ],
        ],
    ]);
}

/**
 * @return array{int, string}
 */
function runAgentIdeMessageWithStdin(string $stdin, array $arguments): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($arguments);
    $input->setStream($stream);
    $output = new BufferedOutput;

    $command = app(AgentIdeMessageCommand::class);
    $command->setLaravel(app());

    return [
        Artisan::all()['agent-ide:message']->run($input, $output),
        trim($output->fetch()),
    ];
}

describe('agent-ide:message', function (): void {
    it('posts app-target messages to the gateway agent ide endpoint', function (): void {
        fakeGateway(agentIdeMessageSuccess());

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            'message' => 'Ship the docs',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/agent-ide/message'
            && $request->data() === [
                'message' => 'Ship the docs',
                'app' => 'docs',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['agent_ide']['target']['app'])->toBe('docs')
            ->and($decoded['success']['data']['agent_ide']['delivery']['input'])->toBe('argument');
    });

    it('posts workspace-target messages to the gateway agent ide endpoint', function (): void {
        fakeGateway(agentIdeMessageSuccess(adapter: 'polyscope', workspace: 'feature-docs'));

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            'message' => 'Ship the docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/agent-ide/message'
            && $request->data() === [
                'message' => 'Ship the docs',
                'workspace' => 'feature-docs',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['agent_ide']['target']['workspace'])->toBe('feature-docs');
    });

    it('uses host cwd for current-directory target resolution when no explicit target is supplied', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/Users/nckrtl/Sites/docs/.worktrees/feature-docs');

        try {
            fakeGateway(agentIdeMessageSuccess(adapter: 'polyscope', workspace: 'feature-docs'));

            [$exitCode] = runCommand($this, 'agent-ide:message', [
                'message' => 'Ship the docs',
                '--json' => true,
            ]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/agent-ide/message'
            && $request->data() === [
                'message' => 'Ship the docs',
                'path' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
            ]);

        expect($exitCode)->toBe(0);
    });

    it('reads stdin messages without trimming the body beyond one trailing newline', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake(['https://gateway.test/*' => Http::response(agentIdeMessageSuccess(input: 'stdin'))]);

        [$exitCode, $output] = runAgentIdeMessageWithStdin("Ship the docs\nwith context\n", [
            '--stdin' => true,
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === [
                'message' => "Ship the docs\nwith context",
                'app' => 'docs',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['agent_ide']['delivery']['input'])->toBe('stdin');
    });

    it('fails before opening a gateway request when message inputs conflict', function (): void {
        Http::fake();

        [$exitCode, $output] = runAgentIdeMessageWithStdin('from stdin', [
            'message' => 'from argument',
            '--stdin' => true,
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta'])->toBe([
                'field' => 'message',
                'reason' => 'conflicting_message_inputs',
            ]);
    });

    it('fails before opening a gateway request when target selectors conflict', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            'message' => 'Ship the docs',
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta'])->toBe([
                'field' => 'target',
                'reason' => 'conflicting_target_options',
            ]);
    });

    it('fails before opening a gateway request when the message is missing in JSON mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta'])->toBe([
                'field' => 'message',
                'reason' => 'missing_required_input',
            ]);
    });

    it('preserves gateway adapter diagnostics under error data', function (): void {
        fakeGateway([
            'error' => [
                'code' => 'adapter_delivery_failed',
                'message' => 'Agent IDE adapter opencode could not deliver the message.',
                'meta' => [
                    'app' => 'docs',
                    'workspace' => null,
                    'adapter' => 'opencode',
                ],
                'data' => [
                    'adapter_error' => [
                        'message' => 'Request timed out',
                    ],
                ],
            ],
        ], 500);

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            'message' => 'Ship the docs',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('adapter_delivery_failed')
            ->and($decoded['error']['data']['adapter_error']['message'])->toBe('Request timed out');
    });

    it('renders the human success summary for accepted delivery', function (): void {
        fakeGateway(agentIdeMessageSuccess());

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            'message' => 'Ship the docs',
            '--app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌ Sending Agent IDE message to docs')
            ->and($output)->toContain('● Resolved target')
            ->and($output)->toContain('● Resolved effective adapter')
            ->and($output)->toContain('● Found active session')
            ->and($output)->toContain('● Delivered message')
            ->and($output)->toContain('└ Sent Agent IDE message to docs through opencode')
            ->and($output)->toContain('Sent message to docs through opencode.')
            ->and($output)->not->toContain('"success"');
    });

    it('renders the human success summary for workspace delivery', function (): void {
        fakeGateway(agentIdeMessageSuccess(adapter: 'polyscope', workspace: 'feature-docs'));

        [$exitCode, $output] = runCommand($this, 'agent-ide:message', [
            'message' => 'Ship the docs',
            '--workspace' => 'feature-docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌ Sending Agent IDE message to docs/feature-docs')
            ->and($output)->toContain('● Resolved target')
            ->and($output)->toContain('● Resolved effective adapter')
            ->and($output)->toContain('● Found active session')
            ->and($output)->toContain('● Delivered message')
            ->and($output)->toContain('└ Sent Agent IDE message to docs/feature-docs through polyscope')
            ->and($output)->toContain('Sent message to docs/feature-docs through polyscope.')
            ->and($output)->not->toContain('"success"');
    });

    it('prompts for a missing message in interactive mode', function (): void {
        fakeGateway(agentIdeMessageSuccess());

        $this->artisan('agent-ide:message', ['--app' => 'docs'])
            ->expectsQuestion('Message', 'Ship the docs')
            ->expectsOutputToContain('Sent message to docs through opencode.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'message' => 'Ship the docs',
            'app' => 'docs',
        ]);
    });
});
