<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\PhpBuildAnswers;

// The Gateway's part of the production PHP runtime: what it renders and sends, and how it reads each answer. The
// agent's Rust tests in apps/agent/src/commands/php_runtime_tests.rs prove what each command does to a runtime.

it('sends the rendered runtime of the recorded identity to converge it', function (): void {
    [$manager, $agents, $instance, $identity] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('php.converge-production-runtime', ['published' => true]));
    $renderer = new ProductionPhpRuntimeConfigRenderer;

    $manager->converge($instance);

    expect($agents->calls[1]['input']['runtime_ini'])->toContain('post_max_size = 64M', 'upload_max_filesize = 64M');
    $input = $agents->calls[2]['input'];
    expect($agents->commands())->toBe(['php.install-build', 'php.configure-runtime', 'php.converge-production-runtime'])
        ->and($input)->toMatchArray([
            'mode' => 'converge', 'user' => $identity->user, 'home' => $identity->home, 'version' => '8.5',
            'marker' => $identity->marker(),
            'application' => $identity->applicationDirectory(),
            'initial_application' => $identity->applicationDirectory(initialRelease: true),
        ])
        ->and($input['configuration'])->toBe([
            'main' => $renderer->render($identity)->main,
            'pool' => $renderer->render($identity)->pool,
            'local_defaults' => $renderer->render($identity)->localDefaults,
            'master_ini' => $renderer->render($identity)->masterIni,
            'unit' => $renderer->render($identity)->unit,
            'initial_pool' => $renderer->render($identity, initialRelease: true)->pool,
        ]);
});

it('sends only the pool monitoring change when service metrics toggle', function (): void {
    [$manager, $agents, $instance, $identity] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('php.converge-production-runtime', ['published' => false]));

    $manager->convergeMonitoring($instance, true);
    $manager->convergeMonitoring($instance, false);

    [$enabled, $disabled] = array_column($agents->calls, 'input');
    expect([$enabled['mode'], $disabled['mode']])->toBe(['monitor', 'monitor'])
        ->and($enabled['configuration']['pool'])->toContain('pm.status_path = /orbit-fpm-status', 'pm.status_listen = '.$identity->socket.'.status')
        ->and($disabled['configuration']['pool'])->not->toContain('pm.status_path', 'pm.status_listen');
});

it('removes the recorded runtime', function (): void {
    [$manager, $agents, $instance, $identity] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('php.remove-production-runtime', ['removed' => true]));

    $manager->remove($instance);

    expect($agents->calls[0]['input'])->toBe(['user' => $identity->user, 'version' => '8.5', 'marker' => $identity->marker()]);
});

it('fails a convergence the agent refuses with its own step and code', function (): void {
    [$manager, , $instance] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->refuse('php.converge-production-runtime', 'php.runtime_invalid', ['check' => 'local.conf only tunes the pool']));

    try {
        $manager->converge($instance);
        throw new LogicException('The convergence did not fail.');
    } catch (RuntimeConvergenceException $exception) {
        expect($exception->step)->toBe('app-prod-php-runtime-converge')
            ->and($exception->errorCode)->toBe('app-prod.php_runtime_convergence_failed')
            ->and($exception->result?->stdout)->toContain('local.conf only tunes the pool');
    }
});

it('refreshes the cache of the recorded runtime within its deadline', function (): void {
    [$manager, $agents, $instance, $identity] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('php.refresh-production-cache', ['complete' => true]));

    $manager->refreshCache($instance);

    expect($agents->calls[0]['input'])->toBe([
        'user' => $identity->user, 'home' => $identity->home, 'version' => '8.5', 'marker' => $identity->marker(), 'deadline_seconds' => 30,
    ]);
});

it('refuses an invalid recorded identity before it contacts the Node', function (): void {
    [$manager, $agents, $instance] = php_runtime_commands();
    $instance->update(['production_php_socket' => '/run/php/another-runtime.sock']);

    expect(fn () => $manager->refreshCache($instance->refresh()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app-prod.php_runtime_identity_invalid'));
    expect($agents->calls)->toBe([]);
});

it('maps each bounded cache refusal to its distinct error code', function (string $refusal, string $errorCode): void {
    [$manager, , $instance] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->refuse('php.refresh-production-cache', $refusal));

    try {
        $manager->refreshCache($instance);
        throw new LogicException('The refresh did not fail.');
    } catch (RuntimeConvergenceException $exception) {
        expect($exception->step)->toBe('app-prod-php-cache-refresh')
            ->and($exception->errorCode)->toBe($errorCode);
    }
})->with([
    'unavailable socket' => ['php.cache_socket_unavailable', 'app-prod.php_cache_socket_unavailable'],
    'wrong service association' => ['php.cache_association_invalid', 'app-prod.php_cache_association_invalid'],
    'reset rejected' => ['php.cache_reset_rejected', 'app-prod.php_cache_reset_rejected'],
    'reset pending at deadline' => ['php.cache_reset_pending', 'app-prod.php_cache_reset_pending'],
    'protocol failure' => ['php.cache_protocol_failed', 'app-prod.php_cache_refresh_failed'],
]);

it('keeps a transport failure of the cache refresh apart from the bounded outcomes', function (): void {
    [$manager, , $instance] = php_runtime_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('php.refresh-production-cache', fn (): array => throw new AgentRunnerFailure('php.refresh-production-cache', AgentRunnerFailure::Transport, 'The connection closed.')));

    expect(fn () => $manager->refreshCache($instance))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-prod.php_cache_refresh_failed'));
});

/** @return array{RemoteProductionPhpRuntimeManager, FakeAgentCommandRunner, Instance, ProductionPhpRuntimeIdentity} */
function php_runtime_commands(?Closure $configure = null): array
{
    $node = Node::query()->create(['name' => 'php-runtime', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.215', 'wireguard_ip' => '10.44.0.215', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'PHP runtime', 'slug' => 'php-runtime', 'repository_url' => 'https://example.test/php-runtime.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = "orbit-app-{$project->id}";
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'primary', 'environment' => 'production',
        'checkout_path' => "/home/{$user}", 'production_user' => $user, 'production_home' => "/home/{$user}",
        'root' => 'public', 'selected_php_version' => '8.5',
    ]);
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());
    $agents = (new FakeAgentCommandRunner)->answer('php.install-build', PhpBuildAnswers::result())->answer('php.configure-runtime', ['changed' => false, 'reloaded' => false]);
    if ($configure !== null) {
        $configure($agents);
    }

    return [new RemoteProductionPhpRuntimeManager(new ProductionPhpRuntimeConfigRenderer, $agents), $agents, $instance->refresh(), $identity];
}
