<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\DetachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-service-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/home/orbit/.orbit/ca/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @return array<string, bool|int|string|null> */
function nodeServiceCliData(): array
{
    return ['id' => 4, 'instance_id' => 12, 'node_id' => 9, 'process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
        'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT', 'changed' => true, 'reason_code' => null];
}

describe('Node service target commands', function (): void {
    it('prints exactly one complete JSON envelope when several inputs are invalid', function (string $command, array $input, string $code): void {
        $mock = MockClient::global();
        $exit = Artisan::call($command, [...$input, '--json' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(1)->and($output)->toHaveKeys(['error'])->and($output['error']['code'])->toBe($code);
        $mock->assertNothingSent();
    })->with([
        'attach missing options' => ['instance:node-service:attach', ['instance' => '12'], 'instance.node_service_process_required'],
        'attach invalid ID and missing options' => ['instance:node-service:attach', ['instance' => 'bad'], 'instance.id_invalid'],
        'detach invalid IDs' => ['instance:node-service:detach', ['instance' => 'bad', 'target' => '0', '--yes' => true], 'instance.id_invalid'],
        'attach omitted Instance' => ['instance:node-service:attach', [], 'instance.id_invalid'],
        'detach omitted IDs' => ['instance:node-service:detach', ['--yes' => true], 'instance.id_invalid'],
    ]);

    it('includes the stable code in human local and Gateway refusals', function (bool $gateway): void {
        $mock = MockClient::global($gateway ? [DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'error' => ['code' => 'instance.node_service_runtime_cleanup_required', 'message' => 'Retry migration first.', 'details' => []],
        ], 409, ['X-Orbit-Request-Id' => '11111111-1111-4111-8111-111111111111'])] : []);
        $arguments = $gateway ? ['instance' => '12', 'target' => '4', '--yes' => true] : ['instance' => 'bad', 'target' => '0', '--yes' => true];
        $exit = Artisan::call('instance:node-service:detach', [...$arguments, '--no-interaction' => true]);
        $output = Artisan::output();
        expect($exit)->toBe(1)->and($output)->toContain('Code: '.($gateway ? 'instance.node_service_runtime_cleanup_required' : 'instance.id_invalid'));
        $mock->assertSentCount($gateway ? 1 : 0);
    })->with([true, false]);

    it('attaches through the typed request and prints clean JSON', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mock = MockClient::global([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => nodeServiceCliData(), 'meta' => ['request_id' => $requestId],
        ])]);

        $this->artisan('instance:node-service:attach', ['instance' => '12', '--process' => '221', '--port' => '1025',
            '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => true])
            ->expectsOutput(json_encode([...nodeServiceCliData(), 'request_id' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertExitCode(0);

        expect($mock->getLastRequest())->toBeInstanceOf(AttachInstanceNodeServiceTargetRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe(['process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
                'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']);
    });

    it('omits an unspecified port from the request', function (): void {
        $mock = MockClient::global([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => nodeServiceCliData(), 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);

        $this->artisan('instance:node-service:attach', ['instance' => '12', '--process' => '221',
            '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => true])->assertExitCode(0);

        expect($mock->getLastRequest()?->body()->all())->not->toHaveKey('port');
    });

    it('renders the completed record-only detach receipt without polling or realtime requests', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $result = [...nodeServiceCliData(), 'detached' => true];
        $mock = MockClient::global([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
                'status' => 'succeeded', 'sequence' => 2, 'result' => $result],
            'meta' => ['request_id' => $requestId],
        ], 202)]);

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true, '--json' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($output)->toMatchArray(['id' => 41, 'status' => 'succeeded', 'result' => $result, 'request_id' => $requestId]);
        $mock->assertSentCount(1);
        $mock->assertSent(fn ($request): bool => $request instanceof DetachInstanceNodeServiceTargetRequest
            && $request->resolveEndpoint() === '/api/v1/instances/12/node-service-targets/4'
            && Str::isUuid($request->headers()->get('Idempotency-Key')));
    });

    it('shows the completed receipt in human output', function (): void {
        $mock = MockClient::global([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
                'status' => 'succeeded', 'sequence' => 2, 'result' => [...nodeServiceCliData(), 'detached' => true]],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ], 202)]);

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('Node service target detached.', 'Operation', '41', 'succeeded')
            ->not->toContain('revoke', 'poll', "\e[");
        $mock->assertSentCount(1);
    });

    it('preserves Gateway detach refusals and request correlation', function (string $code, int $status): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mock = MockClient::global([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'error' => ['code' => $code, 'message' => 'Detach refused.', 'details' => []], 'meta' => ['request_id' => $requestId],
        ], $status, ['X-Orbit-Request-Id' => $requestId])]);

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true, '--json' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)->and($output['error'])->toMatchArray(['code' => $code, 'request_id' => $requestId]);
        $mock->assertSentCount(1);
    })->with([
        'missing or foreign' => ['instance.node_service_target_missing', 404],
        'active lifecycle' => ['instance.lifecycle_busy', 409],
        'failed migration retained runtime' => ['instance.node_service_runtime_cleanup_required', 409],
        'target lock' => ['operation.target_busy', 409],
    ]);

    it('rejects domain selectors and invalid target IDs before sending', function (array $input, string $code): void {
        $mock = MockClient::global();

        $exit = Artisan::call('instance:node-service:detach', [...$input, '--yes' => true, '--json' => true]);

        expect($exit)->toBe(1)->and(Artisan::output())->toContain($code);
        $mock->assertNothingSent();
    })->with([
        'domain' => [['instance' => 'app.example.test', 'target' => '4'], 'instance.id_invalid'],
        'target' => [['instance' => '12', 'target' => '0'], 'instance.node_service_target_id_invalid'],
    ]);

    it('reports an identical attach as skipped with its stable reason', function (bool $json): void {
        $data = [...nodeServiceCliData(), 'changed' => false, 'reason_code' => 'instance.node_service_target_unchanged'];
        $mock = MockClient::global([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);

        $exit = Artisan::call('instance:node-service:attach', ['instance' => '12', '--process' => '221',
            '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => $json, '--no-interaction' => true]);

        $output = Artisan::output();
        expect($exit)->toBe(0)->and($output)->toContain('instance.node_service_target_unchanged');
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toMatchArray($data);
        } else {
            expect($output)->toContain('Skipped', 'Already attached (instance.node_service_target_unchanged)')->not->toContain('Attached Node service target.');
        }
        $mock->assertSentCount(1);
    })->with([true, false]);

    it('requires consent before a machine detach sends a request', function (): void {
        $mock = MockClient::global();

        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--json' => true]);

        expect($exit)->toBe(1);
        expect(Artisan::output())->toContain('instance.node_service_confirmation_required');
        $mock->assertNothingSent();
    });

    it('refuses an omitted detach target without prompting in machine or noninteractive mode', function (string $mode): void {
        $mock = MockClient::global();
        $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', '--yes' => true, $mode => true]);
        $output = Artisan::output();

        expect($exit)->toBe(1);
        if ($mode === '--json') {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('instance.node_service_target_id_invalid');
        } else {
            expect($output)->toContain('Code: instance.node_service_target_id_invalid');
        }
        $mock->assertNothingSent();
    })->with(['--json', '--no-interaction']);

    it('refuses invalid ports and missing required keys before sending', function (array $change, string $code): void {
        $mock = MockClient::global();
        $input = ['instance' => '12', '--process' => '221', '--port' => '1025', '--host-env-key' => 'MAIL_HOST', '--port-env-key' => 'MAIL_PORT', '--json' => true];

        $exit = Artisan::call('instance:node-service:attach', [...$input, ...$change]);

        expect($exit)->toBe(1);
        expect(Artisan::output())->toContain($code);
        $mock->assertNothingSent();
    })->with([
        'explicit empty port' => [['--port' => ''], 'validation.failed'],
        'bad port' => [['--port' => '65536'], 'validation.failed'],
        'missing key' => [['--host-env-key' => ''], 'instance.node_service_host_key_required'],
    ]);
});

/** @param list<string> $arguments @param list<string> $keys @param list<array<string, mixed>> $replies
 * @return array{status: int, output: string, trace: list<array<string, mixed>>}
 */
function nodeServiceTerminal(string $directory, array $arguments, array $keys, array $replies, string $prompt = 'Instance'): array
{
    mkdir($directory, recursive: true);
    $configuration = ['home' => $directory.'/home', 'trace' => $directory.'/trace.jsonl', 'arguments' => $arguments,
        'replies' => $replies, 'keys' => array_map(base64_encode(...), $keys), 'prompt' => $prompt, 'columns' => 140, 'idle_timeout' => 8];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixture = dirname(__DIR__, 2).'/Fixtures/Console/';
    $process = new Process(['python3', $fixture.'command-consent-pty.py', PHP_BINARY, $fixture.'command-consent.php', $directory.'/case.json']);
    $process->setTimeout(20);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['restored'])->toBeTrue()->and($result['keys_remaining'])->toBe(0);
    $raw = base64_decode($result['raw'], strict: true);
    file_put_contents($directory.'/raw.bin', $raw);
    $trace = is_file($directory.'/trace.jsonl') ? array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($directory.'/trace.jsonl', FILE_IGNORE_NEW_LINES)) : [];

    return ['status' => $result['status'], 'output' => $raw, 'trace' => $trace];
}

it('selects omitted attach IDs and prompts for both keys in a real mocked terminal', function (): void {
    $instance = ['id' => 12, 'project_id' => 2, 'node_id' => 9, 'name' => 'default', 'source_layout' => 'checkout', 'checkout_path' => '/srv/example',
        'project' => ['id' => 2, 'name' => 'Example', 'slug' => 'example'], 'node' => ['id' => 9, 'name' => 'beast']];
    $process = ['id' => 221, 'target_type' => 'node', 'target_id' => 9, 'name' => 'mailpit', 'runtime' => 'docker'];
    $replies = [
        ['class' => ListInstancesRequest::class, 'body' => ['data' => [$instance]]],
        ['class' => ShowInstanceRequest::class, 'body' => ['data' => $instance]],
        ['class' => ListProcessesRequest::class, 'body' => ['data' => [$process]]],
        ['class' => AttachInstanceNodeServiceTargetRequest::class, 'body' => ['data' => nodeServiceCliData(), 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']]],
    ];
    $result = nodeServiceTerminal($this->orbitHome.'/attach-terminal', ['instance:node-service:attach'],
        ["\r", "\r", ...str_split('MAIL_HOST'), "\r", ...str_split('MAIL_PORT'), "\r"], $replies);

    expect($result['status'])->toBe(0)->and($result['output'])->toContain('/ Search', 'mailpit', 'Host environment key', 'Port environment key', 'Node service target attached.')
        ->and($result['trace'])->toHaveCount(4)
        ->and($result['trace'][2]['query'])->toBe(['target_type' => 'node', 'target_id' => 9])
        ->and($result['trace'][3]['body'])->toBe(['process_id' => 221, 'protocol' => 'tcp', 'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']);
    foreach ([0, 1, 3] as $index) {
        expect($result['trace'][$index])->not->toHaveKey('query');
    }
});

it('requires terminal detach consent and never sends on decline or cancellation', function (array $keys, bool $consent): void {
    $replies = $consent ? [['class' => DetachInstanceNodeServiceTargetRequest::class, 'status' => 202,
        'body' => ['data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
            'status' => 'succeeded', 'sequence' => 2, 'result' => [...nodeServiceCliData(), 'detached' => true]]]]] : [];
    $result = nodeServiceTerminal($this->orbitHome.'/detach-terminal', ['instance:node-service:detach', '12', '4'], $keys, $replies);

    expect($result['status'])->toBe($consent ? 0 : 1)
        ->and($result['output'])->toContain('Network access, environment and Process stay unchanged.')
        ->and($result['trace'])->toHaveCount($consent ? 1 : 0);
    if ($consent) {
        expect($result['trace'][0]['method'])->toBe('DELETE')
            ->and($result['trace'][0]['url'])->toEndWith('/api/v1/instances/12/node-service-targets/4');
    } else {
        expect($result['output'])->toContain('Code: input.cancelled');
    }
})->with([
    'default No' => [["\r"], false],
    'explicit Yes' => [["\x1b[D", "\r"], true],
    'Ctrl-C' => [["\x03"], false],
    'Ctrl-D' => [["\x04"], false],
]);

it('prompts for an omitted detach ID, validates before consent and sends nothing on cancellation', function (array $keys, bool $consent, bool $invalid): void {
    $replies = $consent ? [['class' => DetachInstanceNodeServiceTargetRequest::class, 'status' => 202,
        'body' => ['data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
            'status' => 'succeeded', 'sequence' => 2, 'result' => [...nodeServiceCliData(), 'detached' => true]]]]] : [];
    $result = nodeServiceTerminal($this->orbitHome.'/detach-id-terminal', ['instance:node-service:detach', '12'], $keys, $replies, 'Node service target ID');

    expect($result['status'])->toBe($consent ? 0 : 1)
        ->and($result['output'])->toContain('Use the attachment ID from the attach result; no attachment list is available.')
        ->and($result['trace'])->toHaveCount($consent ? 1 : 0);
    if ($invalid) {
        expect($result['output'])->toContain('Node service target ID must be a positive integer.');
    }
    if ($consent) {
        expect($result['output'])->toContain('Delete Node service target record [4] from Instance [12]?')
            ->and($result['trace'][0]['method'])->toBe('DELETE')
            ->and($result['trace'][0]['url'])->toEndWith('/api/v1/instances/12/node-service-targets/4');
    } else {
        expect($result['output'])->toContain('Code: input.cancelled');
    }
})->with([
    'typed ID then Yes' => [['4', "\r", "\x1b[D", "\r"], true, false],
    'invalid text then correction' => [[...str_split('bad'), "\r", "\x7f", "\x7f", "\x7f", '4', "\r", "\x1b[D", "\r"], true, true],
    'zero then correction' => [['0', "\r", "\x7f", '4', "\r", "\x1b[D", "\r"], true, true],
    'typed ID then No' => [['4', "\r", "\r"], false, false],
    'Ctrl-C at ID prompt' => [["\x03"], false, false],
    'Ctrl-D at ID prompt' => [["\x04"], false, false],
]);
