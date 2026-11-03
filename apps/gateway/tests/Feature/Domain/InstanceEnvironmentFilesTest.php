<?php

declare(strict_types=1);

use App\Actions\NodeFacts\AgentWatchListAction;
use App\Domain\Instances\Environment\InstanceEnvironmentFiles;

it('names each development .env on the Node for the agent, with no home to bind', function (): void {
    $node = dev935_instance('env-files')->node;
    $files = app(InstanceEnvironmentFiles::class);

    expect($files->forNode($node))->toBe(['/fast/apps/env-files/default/.env'])
        ->and($files->homes($node))->toBe([])
        ->and(app(AgentWatchListAction::class)->execute($node)['environments'])->toBe(['/fast/apps/env-files/default/.env']);
});

it('names a production .env in its home, whose home the agent binds back', function (): void {
    $instance = dev935_instance('env-files-prod', role: 'app-prod');
    $instance->update(['environment' => 'production', 'production_user' => 'orbit-app-7', 'production_home' => '/home/orbit-app-7',
        'checkout_path' => '/home/orbit-app-7/releases/r1']);
    $node = $instance->node;
    $files = app(InstanceEnvironmentFiles::class);

    expect($files->forNode($node))->toBe(['/home/orbit-app-7/.env'])
        ->and($files->homes($node))->toBe(['/home/orbit-app-7']);
});
