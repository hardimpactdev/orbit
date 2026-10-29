<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodePhpPackagesAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Operation;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\PhpBuildAnswers;

it('keeps retained production callers synchronous even when the Node has a queued hold', function (LifecycleStatus $status): void {
    $node = FleetFixtures::node('retained-packages', [RoleName::AppProd], status: $status);
    $held = app(StartOperationAction::class)->execute('firewall.allow', 'node', $node->id, $node->id, [], 'test', null);
    $agents = new FakeAgentCommandRunner;
    (new PhpBuildAnswers)->register($agents);
    app()->instance(AgentCommandRunner::class, $agents);

    $manager = app(RemotePhpPackageManager::class);
    $manager->installForProductionRuntime($node, collect(['8.5']));
    $manager->installForProductionRuntime($node, collect(['8.5']));

    expect(array_column($agents->calls, 'command'))->toBe(['php.install-build', 'php.configure-runtime', 'php.install-build', 'php.configure-runtime']);
    foreach ($agents->calls as $call) {
        if ($call['command'] === 'php.install-build') {
            expect($call['input'])->toMatchArray(['fpm' => 'none', 'extras' => 'keep']);
        } else {
            expect($call['input']['version'])->toBe('8.5')
                ->and($call['input']['runtime_ini'])->toContain('post_max_size = 64M', 'upload_max_filesize = 64M');
        }
    }
    expect(Operation::query()->sole()->id)->toBe($held->id)->and($held->refresh()->status->value)->toBe('queued');
})->with([LifecycleStatus::Active, LifecycleStatus::Failed]);

it('performs no work or admission for an empty retained version selection', function (): void {
    $node = FleetFixtures::node('empty-packages', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    app(RemotePhpPackageManager::class)->installForProductionRuntime($node, collect());

    expect($agents->calls)->toBe([]);
    $this->assertDatabaseCount('operations', 0);
});

it('keeps retained source and installation errors in the app-prod contract', function (string $outcome, string $code): void {
    $node = FleetFixtures::node('refused-packages', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    (new PhpBuildAnswers(['apply' => [$outcome, []]]))->register($agents);
    app()->instance(AgentCommandRunner::class, $agents);

    expect(fn () => app(RemotePhpPackageManager::class)->installForProductionRuntime($node, collect(['8.5'])))
        ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe($code));
    $this->assertDatabaseCount('operations', 0);
})->with([['download_failed', 'app-prod.php_package_source_unavailable'], ['install_failed', 'app-prod.php_install_failed']]);

it('rejects retained installer responses without a data array', function (): void {
    $node = FleetFixtures::node('malformed-packages', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    $agents->answerMalformed('php.install-build', ['outcome' => 'current']);
    app()->instance(AgentCommandRunner::class, $agents);

    expect(fn () => app(RemotePhpPackageManager::class)->installForProductionRuntime($node, collect(['8.5'])))
        ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe('app-prod.php_install_failed'));
    $this->assertDatabaseCount('operations', 0);
});

it('keeps retained stale and deferred FPM warnings', function (): void {
    $node = FleetFixtures::node('warning-packages', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('php.install-build', PhpBuildAnswers::result('applied', ['stale_masters' => ['orbit-fpm-a.service'], 'deferred' => ['orbit-fpm-b.service']]));
    $agents->answer('php.configure-runtime', ['changed' => true, 'reloaded' => false, 'deferred' => ['orbit-fpm-c.service']]);
    app()->instance(AgentCommandRunner::class, $agents);
    Log::spy();

    app(RemotePhpPackageManager::class)->installForProductionRuntime($node, collect(['8.5']));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['masters'] === ['orbit-fpm-a.service'])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['masters'] === ['orbit-fpm-b.service'])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['masters'] === ['orbit-fpm-c.service'])->once();
});

it('refuses standalone package admission and keeps the registry valid without Kit C', function (): void {
    $node = FleetFixtures::node('standalone-packages', [RoleName::AppProd]);
    $code = OperationKinds::supports('list_of') ? 'operation.child_forbidden' : 'operation.kind_unavailable';

    expect(fn () => app(StartNodePhpPackagesAction::class)->execute($node, ['8.5']))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(array_key_exists('node.prepare_php_packages', OperationKinds::definitions()))->toBe(OperationKinds::supports('list_of'));
    $this->assertDatabaseCount('operations', 0);
});
