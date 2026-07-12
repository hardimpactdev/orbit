<?php

declare(strict_types=1);

use App\Librarian\TransitionalSshConsumerClassifier;
use App\Librarian\TransitionalSshConsumerFinder;
use App\Librarian\TransitionalSshInventoryBuilder;
use Illuminate\Support\Facades\Artisan;

it('classifies every production SSH consumer with an exact policy marker', function (): void {
    $inventory = app(TransitionalSshInventoryBuilder::class)->build();
    $transitionalPaths = array_column($inventory['transitional_ssh'], 'path');

    expect($inventory['unmarked_consumers'])
        ->toBeEmpty()
        ->and($inventory['generated_from']['source_roots'])
        ->toBe([
            'apps/gateway/app',
            'apps/cli/app',
        ])
        ->and(array_column($inventory['provisioning_ssh'], 'path'))
        ->toContain('apps/gateway/app/Services/Nodes/GatewayNodeCreator.php')
        ->and($transitionalPaths)
        ->toContain(
            'apps/cli/app/Commands/Node/NodeManageCommand.php',
            'apps/cli/app/Commands/Tool/ToolShowCommand.php',
        )
        ->not->toContain(
            'apps/cli/app/Commands/Tool/ToolCredentialsCommand.php',
            'apps/cli/app/Commands/Tool/ToolInstallCommand.php',
            'apps/cli/app/Commands/Tool/ToolReconfigureCommand.php',
            'apps/cli/app/Commands/Tool/ToolRemoveCommand.php',
            'apps/cli/app/Commands/Tool/ToolUpdateCommand.php',
        );
});

it('discovers public CLI transitional selectors without treating fixed tool lanes as consumers', function (): void {
    $consumers = array_keys(app(TransitionalSshConsumerFinder::class)->find());

    expect($consumers)
        ->toContain(
            'apps/cli/app/Commands/Node/NodeManageCommand.php',
            'apps/cli/app/Commands/Tool/ToolShowCommand.php',
        )
        ->not->toContain(
            'apps/cli/app/Commands/Tool/ToolCredentialsCommand.php',
            'apps/cli/app/Commands/Tool/ToolInstallCommand.php',
            'apps/cli/app/Commands/Tool/ToolReconfigureCommand.php',
            'apps/cli/app/Commands/Tool/ToolRemoveCommand.php',
            'apps/cli/app/Commands/Tool/ToolUpdateCommand.php',
        );
});

it('keeps the committed transitional SSH inventory fresh', function (): void {
    $builder = app(TransitionalSshInventoryBuilder::class);

    expect($builder->artifactPath())
        ->toBeFile()
        ->and(file_get_contents($builder->artifactPath()))
        ->toBe($builder->toJson($builder->build()));
});

it('reports unmarked consumers and rejects conflicting lane markers', function (): void {
    $classifier = app(TransitionalSshConsumerClassifier::class);

    expect(
        $classifier->classify(consumers: [
            'apps/gateway/app/Example.php' => '<?php',
        ])['unmarked_consumers'],
    )->toBe(['apps/gateway/app/Example.php']);

    expect(fn (): array => $classifier->classify(consumers: [
        'apps/gateway/app/Conflicting.php' => <<<'PHP'
            <?php
            // @orbit-ssh-lane transitional-ssh
            // @orbit-ssh-lane provisioning-ssh
            PHP,
    ]))
        ->toThrow(
            RuntimeException::class,
            'SSH consumer apps/gateway/app/Conflicting.php declares both execution-lane markers.',
        );
});

it('passes the transitional SSH inventory freshness command', function (): void {
    $exitCode = Artisan::call('orbit:transitional-ssh-inventory', ['--check' => true]);

    expect($exitCode)->toBe(0)->and(Artisan::output())->toContain('Orbit transitional SSH inventory is up to date.');
});
