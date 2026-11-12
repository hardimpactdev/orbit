<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRuntimeRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-runtime-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/unused-ca'));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @param list<string> $arguments
 * @param  list<string>  $keys
 * @param  list<array<string,mixed>>  $replies
 * @return array{status:int,output:string,trace:list<array<string,mixed>>}
 */
function runtimeUpdateTerminal(string $directory, array $arguments, array $keys, array $replies, string $prompt = 'Instance'): array
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

it('selects an omitted Instance and cancels before admission in a real terminal', function (string $key, bool $admit): void {
    $instance = ['id' => 12, 'project_id' => 2, 'node_id' => 9, 'name' => 'default', 'source_layout' => 'container',
        'checkout_path' => '/srv/workspace', 'project' => ['id' => 2, 'name' => 'Example', 'slug' => 'example'],
        'node' => ['id' => 9, 'name' => 'beast']];
    $replies = [['class' => ListInstancesRequest::class, 'body' => ['data' => [$instance]]]];
    if ($admit) {
        $replies[] = ['class' => UpdateInstanceRuntimeRequest::class, 'status' => 202, 'body' => ['data' => [
            'id' => 41, 'kind' => 'instance.runtime_update', 'target_type' => 'instance', 'target_id' => 12,
            'status' => 'succeeded', 'sequence' => 2, 'result' => ['status' => 'unchanged', 'versions' => ['24.22.1'], 'pins' => []]]]];
        $replies[] = ['class' => ShowRealtimeRequest::class, 'status' => 503, 'body' => []];
    }
    $result = runtimeUpdateTerminal($this->orbitHome.'/picker', ['instance:runtime:update'], [$key], $replies);
    expect($result['status'])->toBe($admit ? 0 : 1, $result['output'])
        ->and($result['output'])->toContain('/ Search', 'default', 'example')
        ->and($result['trace'])->toHaveCount($admit ? 3 : 1)
        ->and($result['trace'][0]['method'])->toBe('GET')
        ->and($result['trace'][0]['url'])->toEndWith('/api/v1/instances');
    if ($admit) {
        expect($result['trace'][1]['method'])->toBe('POST')
            ->and($result['trace'][1]['url'])->toEndWith('/api/v1/instances/12/runtime')
            ->and($result['output'])->toContain('No Process was restarted.');
    } else {
        expect($result['output'])->toContain('input.cancelled');
    }
})->with(['select' => ["\r", true], 'Ctrl-C' => ["\x03", false], 'Ctrl-D' => ["\x04", false]]);
