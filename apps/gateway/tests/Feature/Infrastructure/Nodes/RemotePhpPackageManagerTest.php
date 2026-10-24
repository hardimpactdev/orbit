<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\PhpBuildAnswers;

beforeEach(function (): void {
    $this->agents = new FakeAgentCommandRunner;
    (new PhpBuildAnswers)->register($this->agents);
    $this->agents->answer('php.configure-runtime', ['changed' => false, 'reloaded' => false]);
    app()->instance(AgentCommandRunner::class, $this->agents);
});

it('installs exact recipe pins once and configures each selected runtime through the agent', function (): void {
    $recipe = new RecipeLoader()->load();
    new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.4', '8.5', '8.5']));
    expect(array_column($this->agents->calls, 'command'))->toBe(['php.install-build', 'php.configure-runtime', 'php.configure-runtime']);
    $input = $this->agents->calls[0]['input'];
    expect($input)->not->toHaveKey('mode')
        ->toMatchArray(['extras' => 'keep', 'fpm' => 'shared', 'pcov' => true, 'opentelemetry' => true, 'mirror' => $recipe->artifactMirror,
            'repack' => ['package' => 'dpkg-repack', 'version' => $recipe->tools['dpkg-repack']->version],
            'release' => ['id' => 'ubuntu', 'codenames' => ['resolute']]])
        ->and(array_keys($input['versions']))->toBe(['8.4', '8.5'])
        ->and($input['versions']['8.5']['extensions'])->toBe($recipe->php('8.5')->cliExtensions())
        ->and(array_values(array_unique(array_column($input['versions']['8.5']['packages'], 'name'))))->toBe($recipe->php('8.5')->packages);
    foreach ($input['versions']['8.5']['packages'] as $pin) {
        expect($pin['version'])->toBe($recipe->tools[$pin['name']]->version);
    }
    expect($this->agents->calls[1]['input']['version'])->toBe('8.4')
        ->and($this->agents->calls[2]['input']['version'])->toBe('8.5')
        ->and($this->agents->calls[2]['input']['runtime_ini'])->toContain('opcache.memory_consumption = 512');
});

it('keeps PCOV and OpenTelemetry placement consistent across roles on one Node', function (bool $development): void {
    $node = php_package_node(RoleName::AppProd);
    if ($development) {
        $node->roles()->create(['role' => RoleName::AppDev]);
    }
    $node->load('roles');
    $manager = new RemotePhpPackageManager;
    $manager->installForAppDev($node, collect(['8.5']));
    $manager->installForInstance($node, collect(['8.5']), RoleName::AppProd);
    $manager->installForAppProd($node, collect(['8.5']));
    $manager->installPackagesOnlyForAppProd($node, collect(['8.5']));
    $calls = collect($this->agents->calls)->where('command', 'php.install-build')->values();
    expect($calls)->toHaveCount(4);
    foreach ($calls as $call) {
        expect($call['input']['pcov'])->toBe($development)
            ->and($call['input']['opentelemetry'])->toBe($development)
            ->and(in_array('php8.5-opentelemetry', array_column($call['input']['versions']['8.5']['packages'], 'name'), true))->toBe($development);
    }
    expect($calls[3]['input']['fpm'])->toBe('none');
    $profiles = collect($this->agents->calls)->where('command', 'php.configure-runtime')->values();
    expect($profiles)->toHaveCount(3)
        ->and($profiles[2]['input']['runtime_ini'])->toContain('opcache.memory_consumption = '.($development ? '512' : '256'));
})->with([true, false]);

it('performs no work for an empty version set or an unsupported recipe version', function (): void {
    $node = php_package_node(RoleName::AppDev);
    $manager = new RemotePhpPackageManager;
    $manager->installForAppDev($node, collect());
    $manager->installPackagesOnlyForAppProd($node, collect());
    expect(fn () => $manager->installForAppDev($node, collect(['8.6'])))
        ->toThrow(fn (RuntimeConvergenceException $e) => expect($e->errorCode)->toBe('app-dev.php_package_source_unavailable')->and($e->getMessage())->toContain('no PHP 8.6 build'));
    expect($this->agents->calls)->toBe([]);
});

it('maps typed build refusals to the stable role-specific error', function (string $code, array $details, bool $source, string $message, string $role): void {
    $this->agents->refuse('php.install-build', 'php.build_'.$code, $details);
    $node = php_package_node($role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd);
    $manager = new RemotePhpPackageManager;
    $run = $role === 'app-dev'
        ? fn () => $manager->installForAppDev($node, collect(['8.5']))
        : fn () => $manager->installForAppProd($node, collect(['8.5']));
    expect($run)->toThrow(function (RuntimeConvergenceException $e) use ($role, $source, $message, $code): void {
        expect($e->errorCode)->toBe($role.($source ? '.php_package_source_unavailable' : '.php_install_failed'))
            ->and($e->getMessage())->toContain($message)
            ->and($e->result?->stdout)->toContain('php.build_'.$code);
    });
    expect($this->agents->calls)->toHaveCount(1);
})->with([
    ['conflicting_source', ['sources' => ['/etc/apt/sources.list.d/ondrej.list']], true, 'ondrej.list'],
    ['download_failed', ['package' => 'php8.5-cli', 'reason' => 'no source served the pinned file'], true, 'php8.5-cli'],
    ['platform_unsupported', ['id' => 'ubuntu', 'codename' => 'noble'], true, 'ubuntu/noble'],
    ['rolled_back', ['unit' => 'php8.5-fpm.service', 'restored' => true], false, 'php8.5-fpm.service'],
    ['modules_mismatch', ['missing' => ['pcov'], 'extra' => []], false, 'Missing: [pcov]'],
])->with(['app-dev', 'app-prod']);

it('preserves installer failure state and timeout distinctions without a script retry', function (string $kind): void {
    $failure = new AgentRunnerFailure('php.install-build', $kind, 'No result.');
    $this->agents->answer('php.install-build', static fn (): array => throw $failure);
    $node = php_package_node(RoleName::AppDev);
    expect(fn () => new RemotePhpPackageManager()->installForAppDev($node, collect(['8.5'])))
        ->toThrow($kind === AgentRunnerFailure::Timeout ? AgentRunnerFailure::class : RuntimeConvergenceException::class);
    expect($this->agents->calls)->toHaveCount(1);
})->with([AgentRunnerFailure::MissingResult, AgentRunnerFailure::Transport, AgentRunnerFailure::Timeout]);

it('preserves agent installation refusals', function (): void {
    $this->agents->answer('php.install-build', static fn (): array => throw new AgentCommandRefused('agent.install', 'agent.install_failed', 'Installation failed.'));
    expect(fn () => new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5'])))
        ->toThrow(fn (AgentCommandRefused $e) => expect($e->errorCode)->toBe('agent.install_failed'));
});

it('maps a runtime publication refusal to the installation step', function (): void {
    $this->agents->refuse('php.configure-runtime', 'php.runtime_configuration_failed', ['reason' => 'unmanaged override', 'restored' => true]);
    expect(fn () => new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5'])))
        ->toThrow(fn (RuntimeConvergenceException $e) => expect($e->errorCode)->toBe('app-dev.php_install_failed')->and($e->result?->stdout)->toContain('unmanaged override'));
});

it('logs skipped downloads and masters left for later publication', function (): void {
    Log::spy();
    $this->agents->answer('php.install-build', ['outcome' => 'current', 'data' => ['stale_masters' => ['orbit-shop-php8.5-fpm.service'], 'deferred' => ['php8.5-fpm.service']]]);
    new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5']));
    Log::shouldHaveReceived('info')->once()->with('PHP packages match the recipe; skipped the package download.', Mockery::any());
    Log::shouldHaveReceived('warning')->with('Dedicated PHP-FPM masters were not restarted or reloaded; they may still use the previous PHP build or settings.', Mockery::on(static fn (array $c): bool => $c['masters'] === ['orbit-shop-php8.5-fpm.service']));
    Log::shouldHaveReceived('warning')->with('PHP-FPM masters failed configuration validation; deferred their restart or reload until their configuration is repaired.', Mockery::on(static fn (array $c): bool => $c['masters'] === ['php8.5-fpm.service']));
});

it('contains no direct execution path or program assembly', function (): void {
    $source = file_get_contents(app_path('Infrastructure/Nodes/RemotePhpPackageManager.php'));
    foreach (['SshExecutor', 'SshProgramRunner', 'RemoteProgramCatalog', '<<<', 'python3', 'bash', 'shell_exec'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

function php_package_node(RoleName $role): Node
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => 'php-package-node-'.str_replace(search: '-', replace: '', subject: $role->value)."-{$count}",
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.44',
        'wireguard_ip' => '10.44.0.'.(44 + $count),
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => $role]);

    return $node->load('roles');
}
