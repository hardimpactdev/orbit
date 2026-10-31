<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\SshKeyProvider;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAA fixture-public');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
});

it('exports typed public input without rendering or running agent actions', function (): void {
    expect(Artisan::call('orbit:node-recipe', ['roles' => ['gateway', 'vpn'], '--json' => true]))->toBe(0);
    $bytes = Artisan::output();
    $wire = json_decode($bytes, false, flags: JSON_THROW_ON_ERROR);
    expect($wire->recipe)->toEqual(json_decode(app(RecipeLoader::class)->load()->json, false, flags: JSON_THROW_ON_ERROR));
    $output = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($output))->toBe(['schema_version', 'node', 'bootstrap', 'recipe', 'agent'])
        ->and($output['schema_version'])->toBe(1)
        ->and($output['node'])->toBe(['platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'home' => '/home/orbit', 'roles' => ['gateway', 'vpn']])
        ->and($output['bootstrap'])->toMatchArray(['user' => 'orbit', 'public_key' => 'ssh-ed25519 AAAA fixture-public'])
        ->and($output['recipe'])->toHaveKeys(['tools', 'roles'])
        ->and($output['agent']['sha256'])->toMatch('/\A[a-f0-9]{64}\z/D')
        ->and($output)->not->toHaveKeys(['programs', 'arguments', 'script', 'fingerprint'])
        ->and(Artisan::all())->not->toHaveKey('orbit:node-programs');
});

it('keeps the public bootstrap DNS policy for a roleless operator', function (): void {
    expect(Artisan::call('orbit:node-recipe', ['--json' => true]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['node']['roles'])->toBe([])->and($output['bootstrap']['dns_policy'])->toBe('preserve');
});

it('prints the same selected Node identity in human output', function (): void {
    expect(Artisan::call('orbit:node-recipe', ['roles' => ['vpn']]))->toBe(0)
        ->and(Artisan::output())->toBe("linux x86_64 orbit vpn\n");
});

it('refuses unsupported or repeated roles and unsupported Linux architecture before exporting', function (array $roles, string $architecture): void {
    expect(Artisan::call('orbit:node-recipe', ['roles' => $roles, '--architecture' => $architecture, '--json' => true]))->toBe(1)
        ->and(Artisan::output())->not->toContain('"recipe"');
})->with([
    [['vpn', 'vpn'], 'x86_64'], [['metrics'], 'x86_64'], [['mainframe'], 'x86_64'], [['vpn'], 'arm64'],
]);
