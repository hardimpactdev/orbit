<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-action-'.Str::uuid();
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

it('makes no mutation and keeps the retained Tool row when removal is refused', function (): void {
    $mock = MockClient::global([
        ShowToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl']),
            'meta' => ['request_id' => '99999999-9999-4999-8999-999999999999'],
        ]),
    ]);

    [$exit, $output] = tool_action_cli_display('tool:remove', ['tool' => '41']);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Supply --yes to confirm this operation.');
    $mock->assertSent(ShowToolRequest::class);
    $mock->assertNotSent(RemoveToolRequest::class);
});

it('rejects nonnumeric IDs for both actions before HTTP', function (): void {
    $mock = MockClient::global();
    foreach (['tool:update', 'tool:remove'] as $command) {
        $this->artisan($command, ['tool' => 'abc'])->assertExitCode(1);
    }
    expect($mock->getLastRequest())->toBeNull();
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function tool_action_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

/** @param array<string, int|string|null> $overrides */
function tool_action_data(array $overrides = []): array
{
    return array_replace([
        'id' => 41,
        'node_id' => 12,
        'manager' => 'vp',
        'package' => '@openai/codex',
        'version_constraint' => null,
        'status' => 'installed',
        'installed_version' => null,
        'failed_operation' => null,
        'error_code' => null,
        'outcome' => null,
    ], $overrides);
}
