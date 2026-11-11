<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\DetachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\ListInstanceNodeServiceTargetsRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
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

/** @return array<string, mixed> */
function nodeServiceCliRecord(): array
{
    $data = nodeServiceCliData();
    unset($data['changed'], $data['reason_code']);

    return $data;
}

describe('Node service target commands', function (): void {
    it('lists the selected Instances records or its empty state in human and JSON output', function (bool $empty, bool $json): void {
        $targets = $empty ? [] : [nodeServiceCliRecord()];
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mock = MockClient::global([ListInstanceNodeServiceTargetsRequest::class => MockResponse::make([
            'data' => $targets, 'meta' => ['request_id' => $requestId],
        ])]);

        $exit = Artisan::call('instance:node-service:list', ['instance' => '12', '--json' => $json, '--no-interaction' => true]);
        $output = Artisan::output();
        expect($exit)->toBe(0);
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe(['targets' => $targets, 'request_id' => $requestId]);
        } else {
            expect($output)->toContain($empty ? 'No Node service targets attached.' : 'MAIL_HOST', 'Request ID: '.$requestId);
        }
        $mock->assertSentCount(1);
        expect($mock->getLastRequest())->toBeInstanceOf(ListInstanceNodeServiceTargetsRequest::class)
            ->and($mock->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/instances/12/node-service-targets')
            ->and($mock->getLastRequest()?->getMethod()->value)->toBe('GET');
    })->with([[false, false], [false, true], [true, false], [true, true]]);

    it('refuses missing or invalid list IDs before a request', function (?string $instance): void {
        $mock = MockClient::global();
        $exit = Artisan::call('instance:node-service:list', ['instance' => $instance, '--json' => true]);
        expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('instance.id_invalid');
        $mock->assertNothingSent();
    })->with([null, 'app.example.test']);

    it('preserves list authority refusals in human and JSON output', function (bool $json): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mock = MockClient::global([ListInstanceNodeServiceTargetsRequest::class => MockResponse::make([
            'error' => ['code' => 'node_access.required', 'message' => 'Node access is required.', 'details' => []],
        ], 403, ['X-Orbit-Request-Id' => $requestId])]);
        $exit = Artisan::call('instance:node-service:list', ['instance' => '12', '--json' => $json, '--no-interaction' => true]);
        $output = Artisan::output();
        expect($exit)->toBe(1)->and($output)->toContain($requestId);
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('node_access.required');
        } else {
            expect($output)->toContain('Code: node_access.required');
        }
        $mock->assertSentCount(1);
    })->with([false, true]);

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
        'signed target' => [['instance' => '12', 'target' => '+4'], 'instance.node_service_target_id_invalid'],
        'whitespace target' => [['instance' => '12', 'target' => ' 4 '], 'instance.node_service_target_id_invalid'],
        'Unicode digits' => [['instance' => '12', 'target' => '４'], 'instance.node_service_target_id_invalid'],
        'out of range target' => [['instance' => '12', 'target' => (string) PHP_INT_MAX.'0'], 'instance.node_service_target_id_invalid'],
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
        ->and($result['output'])->toContain('Withdraw its container access', 'keep environment and Process.')
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

it('selects an omitted detach target from the Instance registry before consent', function (array $keys, bool $consent, int $id): void {
    $records = [nodeServiceCliRecord(), [...nodeServiceCliRecord(), 'id' => 5, 'process_id' => 222, 'port' => 6380,
        'host_env_key' => 'CACHE_HOST', 'port_env_key' => 'CACHE_PORT']];
    $replies = [['class' => ListInstanceNodeServiceTargetsRequest::class, 'body' => ['data' => $records]]];
    if ($consent) {
        $replies[] = ['class' => DetachInstanceNodeServiceTargetRequest::class, 'status' => 202,
            'body' => ['data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
                'status' => 'succeeded', 'sequence' => 2, 'result' => ['id' => $id, 'detached' => true]]]];
    }
    $result = nodeServiceTerminal($this->orbitHome.'/detach-picker', ['instance:node-service:detach', '12'], $keys, $replies, 'Node service target');

    expect($result['status'])->toBe($consent ? 0 : 1)
        ->and($result['output'])->toContain('/ Search', 'MAIL_HOST', 'CACHE_HOST')
        ->and($result['trace'])->toHaveCount($consent ? 2 : 1)
        ->and($result['trace'][0]['method'])->toBe('GET')
        ->and($result['trace'][0]['url'])->toEndWith('/api/v1/instances/12/node-service-targets');
    if ($consent) {
        expect($result['output'])->toContain("Detach Node service target [{$id}] from Instance [12]?")
            ->and($result['trace'][1]['method'])->toBe('DELETE')
            ->and($result['trace'][1]['url'])->toEndWith("/api/v1/instances/12/node-service-targets/{$id}");
    } else {
        expect($result['output'])->toContain('Code: input.cancelled');
    }
})->with([
    'second record then Yes' => [["\x1b[B", "\r", "\x1b[D", "\r"], true, 5],
    'selection Ctrl-C' => [["\x03"], false, 4],
    'selection Ctrl-D' => [["\x04"], false, 4],
    'confirmation default No' => [["\r", "\r"], false, 4],
    'confirmation Ctrl-C' => [["\r", "\x03"], false, 4],
    'confirmation Ctrl-D' => [["\r", "\x04"], false, 4],
]);

it('refuses an empty or denied detach registry before confirmation or deletion', function (bool $denied): void {
    $replies = [['class' => ListInstanceNodeServiceTargetsRequest::class, 'status' => $denied ? 403 : 200,
        'body' => $denied ? ['error' => ['code' => 'node_access.required', 'message' => 'Node access is required.', 'details' => []]] : ['data' => []]]];
    $result = nodeServiceTerminal($this->orbitHome.'/detach-no-choices', ['instance:node-service:detach', '12'], [], $replies);

    expect($result['status'])->toBe(1)->and($result['trace'])->toHaveCount(1)
        ->and($result['trace'][0]['method'])->toBe('GET')
        ->and($result['output'])->toContain('Code: '.($denied ? 'node_access.required' : 'instance.node_service_target_id_invalid'))
        ->not->toContain('Detach Node service target [');
})->with([false, true]);

it('rejects a signed or spaced supplied target before reads or consent and accepts its correction', function (string $id): void {
    $result = nodeServiceTerminal($this->orbitHome.'/detach-invalid-id', ['instance:node-service:detach', '12', $id], [], []);

    expect($result['status'])->toBe(1)->and($result['trace'])->toBe([])
        ->and($result['output'])->toContain('Code: instance.node_service_target_id_invalid')
        ->not->toContain('Detach Node service target [');
    $replies = [['class' => DetachInstanceNodeServiceTargetRequest::class, 'status' => 202,
        'body' => ['data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
            'status' => 'succeeded', 'sequence' => 2, 'result' => ['id' => 4, 'detached' => true]]]]];
    $corrected = nodeServiceTerminal($this->orbitHome.'/detach-corrected-id', ['instance:node-service:detach', '12', '4'], ["\x1b[D", "\r"], $replies);
    expect($corrected['status'])->toBe(0)->and($corrected['trace'])->toHaveCount(1)
        ->and($corrected['trace'][0]['method'])->toBe('DELETE')
        ->and($corrected['trace'][0]['url'])->toEndWith('/api/v1/instances/12/node-service-targets/4');
})->with(['+4', ' 4 ']);

it('waits for an asynchronous withdrawal and returns its terminal receipt instead of claiming admission is success', function (bool $succeeds, bool $json): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    $base = ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
        'node_id' => 9, 'sequence' => 1, 'status' => 'queued', 'result' => []];
    $final = [...$base, 'sequence' => 3, 'status' => $succeeds ? 'succeeded' : 'failed',
        'result' => $succeeds ? ['id' => 4, 'detached' => true] : ['error_code' => 'instance.bridge_busy'],
        'error_code' => $succeeds ? null : 'instance.bridge_busy'];
    $mock = MockClient::global([
        DetachInstanceNodeServiceTargetRequest::class => MockResponse::make(['data' => $base, 'meta' => ['request_id' => $requestId]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => $requestId]]),
    ]);
    $exit = Artisan::call('instance:node-service:detach', ['instance' => '12', 'target' => '4', '--yes' => true,
        '--json' => $json, '--no-interaction' => true]);
    $output = Artisan::output();
    expect($exit)->toBe($succeeds ? 0 : 1);
    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['status' => $final['status'],
            'result' => $final['result'], 'request_id' => $requestId]);
    } else {
        expect($output)->toContain($succeeds ? 'detached after access withdrawal' : 'attachment remains for retry', $requestId);
        if (! $succeeds) {
            expect($output)->toContain('instance.bridge_busy')->not->toContain('Node service target detached.');
        }
    }
    $mock->assertSentCount($json ? 2 : 1, ShowOperationRequest::class);
    $mock->assertSentCount(1, DetachInstanceNodeServiceTargetRequest::class);
})->with([[true, false], [true, true], [false, false], [false, true]]);

it('settles asynchronous detach in a real mocked terminal with the truthful terminal outcome', function (bool $succeeds): void {
    $base = ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
        'node_id' => 9, 'sequence' => 1, 'status' => 'queued', 'result' => []];
    $final = [...$base, 'sequence' => 2, 'status' => $succeeds ? 'succeeded' : 'failed',
        'result' => $succeeds ? ['id' => 4, 'detached' => true] : ['error_code' => 'instance.bridge_busy'],
        'error_code' => $succeeds ? null : 'instance.bridge_busy'];
    $result = nodeServiceTerminal($this->orbitHome.'/detach-async', ['instance:node-service:detach', '12', '4', '--yes'], [], [
        ['class' => DetachInstanceNodeServiceTargetRequest::class, 'status' => 202, 'body' => ['data' => $base]],
        ['class' => ShowRealtimeRequest::class, 'status' => 503, 'body' => []],
        ['class' => ShowOperationRequest::class, 'status' => 200, 'body' => ['data' => $final]],
    ]);
    expect($result['status'])->toBe($succeeds ? 0 : 1)
        ->and($result['trace'])->toHaveCount(3)
        ->and($result['trace'][0]['method'])->toBe('DELETE')
        ->and($result['trace'][2]['url'])->toEndWith('/api/v1/operations/41');
    expect($result['output'])->toContain($succeeds ? 'detached after access withdrawal' : 'attachment remains for retry');
    if (! $succeeds) {
        expect($result['output'])->toContain('instance.bridge_busy')->not->toContain('Node service target detached.');
    }
})->with([false, true]);
