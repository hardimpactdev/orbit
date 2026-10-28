<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodePhpPackagesOperationCompletion;
use App\Domain\Nodes\PrepareNodePhpPackagesCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function phpPackagesPreparation(bool $development = false): array
{
    $node = FleetFixtures::node('php-packages', $development ? [RoleName::AppProd, RoleName::AppDev] : [RoleName::AppProd]);
    $operation = Operation::query()->create(['kind' => 'node.prepare_php_packages', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => ['versions' => ['8.5'], 'profile' => 'app-prod'],
        'status' => 'running', 'worker' => 'php-packages-worker', 'sequence' => 1, 'attempts' => 1]);
    $command = new PrepareNodePhpPackagesCommand(new RemotePhpPackageManager, new ManagedNodeEligibility, app(OperationStore::class));

    return [$node, $operation, $command];
}

it('captures exact recipe pins with package-only policy and no runtime effect', function (bool $development): void {
    [, $operation, $command] = phpPackagesPreparation($development);
    expect($command->run($operation, $operation->input))->toBe(['prepared' => true]);
    $snapshot = $operation->refresh()->input['_php_packages'];
    $recipe = new RecipeLoader()->load();
    expect($snapshot)->toMatchArray(['extras' => 'keep', 'fpm' => 'none', 'pcov' => $development, 'opentelemetry' => $development,
        'mirror' => $recipe->artifactMirror, 'release' => ['id' => 'ubuntu', 'codenames' => ['resolute']]])
        ->and(array_keys($snapshot['versions']))->toBe(['8.5']);
    foreach ($snapshot['versions']['8.5']['packages'] as $package) {
        expect($package['version'])->toBe($recipe->tools[$package['name']]->version)
            ->and($package['sha256'])->toBe($recipe->tools[$package['name']]->artifacts[$package['architecture']]->digest);
    }
    AgentSchemas::assertInput('php.install-build', $snapshot);
})->with([false, true]);

it('keeps the first package snapshot when roles change before retry', function (): void {
    [$node, $operation, $command] = phpPackagesPreparation();
    $command->run($operation, $operation->input);
    $snapshot = $operation->refresh()->input['_php_packages'];
    $node->roles()->create(['role' => RoleName::AppDev]);
    $command->run($operation, ['versions' => ['8.4'], 'profile' => 'app-prod']);
    expect($operation->refresh()->input['_php_packages'])->toBe($snapshot);
});

it('refuses unsupported recipe versions before producing an installer input', function (): void {
    [, $operation, $command] = phpPackagesPreparation();
    expect($command->run($operation, ['versions' => ['99.1'], 'profile' => 'app-prod']))->toBe(['error_code' => 'app-prod.php_package_source_unavailable'])
        ->and($operation->refresh()->input)->not->toHaveKey('_php_packages');
});

it('refuses other profiles and malformed version sets', function (array $input): void {
    [, $operation, $command] = phpPackagesPreparation();
    expect(fn () => $command->run($operation, $input))->toThrow(ResourceOperationException::class)
        ->and($operation->refresh()->input)->not->toHaveKey('_php_packages');
})->with([
    [['versions' => [], 'profile' => 'app-prod']],
    [['versions' => ['8.5'], 'profile' => 'app-dev']],
    [['versions' => ['8.5', '8.5'], 'profile' => 'app-prod']],
    [['versions' => ['8.5; command'], 'profile' => 'app-prod']],
    [['versions' => ['one' => '8.5'], 'profile' => 'app-prod']],
]);

it('refuses unsupported Nodes and unproven installer success', function (): void {
    [$node, $operation, $command] = phpPackagesPreparation();
    $node->update(['platform' => 'macos']);
    expect(fn () => $command->run($operation, $operation->input))->toThrow(ResourceOperationException::class);
    $completion = new NodePhpPackagesOperationCompletion;
    expect(fn () => $completion->complete($operation, OperationOutcome::succeeded(['outcome' => 'planned'])))->toThrow(ResourceOperationException::class);
    $completion->complete($operation, OperationOutcome::succeeded(['outcome' => 'applied']));
});
