<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\AdoptToolRequest;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Process\Process;
use Tests\Support\CommandFixture;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-adopt-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('requires consent before any adoption request when it cannot prompt', function (array $arguments): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-adopt/adopted'));

    $result = CommandFixture::run(['tool:adopt', ...$arguments]);
    expect($result['status'])->toBe(1)->and($result['stderr'])->toBe('');
    $output = $result['output'];
    expect($output)->toContain('Supply --yes to confirm this operation.');
    if (in_array('--json', $arguments, true)) {
        expect($output)->toContain('input.confirmation_required');
    }
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'human' => [['jq', '--node=2', '--manager=apt']],
    'json' => [['jq', '--node=2', '--manager=apt', '--json']],
]);

it('rejects invalid identity before consent or a request', function (array $arguments, string $code): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-adopt/adopted'));

    $result = CommandFixture::run(['tool:adopt', ...$arguments, '--yes', '--json']);
    expect($result['status'])->toBe(1)->and($result['stderr'])->toBe('');
    expect($result['output'])->toContain($code);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'node' => [['jq', '--node=0', '--manager=apt'], 'tool.node_id_invalid'],
    'manager' => [['jq', '--node=2'], 'tool.manager_required'],
    'package' => [["jq\nname", '--node=2', '--manager=apt'], 'tool.package_invalid'],
    'oversized package' => [[str_repeat('x', 256), '--node=2', '--manager=apt'], 'tool.package_invalid'],
]);

it('refuses JSON consent from a terminal without sending the adoption request', function (): void {
    $result = run_tool_adopt_consent(
        ['tool:adopt', 'jq', '--node=2', '--manager=apt', '--json'],
        [],
        [],
    );

    expect($result['status'])->toBe(1)
        ->and($result['prompt_seen'])->toBeFalse()
        ->and($result['requests'])->toBe([])
        ->and($result['output'])->toContain('input.confirmation_required');
});

/**
 * @param  list<string>  $keys
 * @param  list<array<string, mixed>>  $replies
 * @return array<string, mixed>
 */
function run_tool_adopt_consent(array $arguments, array $keys, array $replies): array
{
    $directory = sys_get_temp_dir().'/orbit-tool-adopt-consent-'.Str::uuid();
    new Filesystem()->makeDirectory($directory);
    $configuration = [
        'home' => $directory.'/home',
        'trace' => $directory.'/requests.jsonl',
        'keys' => array_map(base64_encode(...), $keys),
        'arguments' => $arguments,
        'replies' => $replies,
        'prompt' => 'Take ownership of package',
        'columns' => 200,
    ];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixtures = dirname(__DIR__, 2).'/Fixtures/Console/';
    $argv = [PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'];

    try {
        $process = new Process(['python3', $fixtures.'command-consent-pty.py', ...$argv], env: ['PAO_DISABLE' => '1']);
        $process->setTimeout(15);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $result['output'] = base64_decode((string) $result['raw']);
        $result['requests'] = is_file($configuration['trace']) ? array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        ) : [];

        return $result;
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
}

/** @return array<string, mixed> */
function tool_adopt_consent_reply(string $manager = 'apt', ?string $constraint = null): array
{
    return [
        'class' => AdoptToolRequest::class,
        'status' => 201,
        'body' => [
            'data' => [
                'id' => 1,
                'node_id' => 2,
                'manager' => $manager,
                'package' => 'jq',
                'version_constraint' => $constraint,
                'status' => 'installed',
                'installed_version' => '1.2.3',
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'applied',
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ],
    ];
}
