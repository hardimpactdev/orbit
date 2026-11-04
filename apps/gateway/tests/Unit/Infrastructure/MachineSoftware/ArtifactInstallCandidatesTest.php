<?php

declare(strict_types=1);

use App\Domain\Nodes\CaddyRelease;
use App\Domain\Nodes\NativeCaddyPackageInput;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;

it('admits mirror then upstream candidates for Composer Debian prerequisites', function (): void {
    $recipe = new RecipeLoader()->load();
    $input = app(ToolManagerIntent::class)->nativeInput(new Node(['platform' => 'linux', 'user' => 'orbit']), ToolManagerName::Composer);

    foreach ($input['apt']['packages'] as $package) {
        if ($package['artifact'] === null) {
            continue;
        }
        $artifact = $recipe->tools[$package['name']]->artifacts['amd64'];
        expect($package['artifact']['urls'])->toBe([
            $recipe->artifactMirror.'/'.$package['name'].'_'.$artifact->digest.'.deb',
            $artifact->location,
        ])->and($package['artifact']['url'])->toBe($package['artifact']['urls'][0]);
    }
});

it('keeps the single upstream candidate for JavaScript archives', function (): void {
    $recipe = new RecipeLoader()->load();
    $input = app(ToolManagerIntent::class)->nativeInput(new Node(['platform' => 'linux', 'user' => 'orbit']), ToolManagerName::Vp);

    foreach ($input['input']['tools'] as $name => $pin) {
        expect($pin['urls'])->toBe([$recipe->tools[$name]->artifacts['amd64']->location])
            ->and($pin['url'])->toBe($pin['urls'][0]);
    }
});

it('projects mirror then upstream candidates for every role PHP package', function (): void {
    $recipe = new RecipeLoader()->load();
    $build = PhpBuildInstaller::input($recipe, ['8.5'], true, extras: 'keep', fpm: 'all', opentelemetry: true);
    $projection = new ReflectionMethod(NativeNodeRoleAddInput::class, 'phpPackages');
    $packages = $projection->invoke(new NativeNodeRoleAddInput, $build);

    expect($packages)->not->toBeEmpty();
    foreach ($packages as $package) {
        $artifact = $recipe->tools[$package['name']]->artifacts['amd64'];
        expect($package['urls'])->toBe([
            $recipe->artifactMirror.'/'.$package['name'].'_'.$artifact->digest.'.deb',
            $artifact->location,
        ])->and($package['sha256'])->toBe($artifact->digest)
            ->and($package['url'])->toBe($package['urls'][0]);
    }
});

it('admits native Caddy candidates without changing the frozen agent input', function (): void {
    $recipe = new RecipeLoader()->load();
    $artifact = $recipe->tools['caddy']->artifacts['amd64'];
    $candidates = [$recipe->artifactMirror.'/caddy_'.$artifact->digest.'.deb', $artifact->location];
    expect(NativeCaddyPackageInput::make()['urls'])->toBe($candidates)
        ->and(NativeCaddyPackageInput::make()['url'])->toBe($candidates[0]);

    $agentInput = [
        'release' => AgentCaddyPackage::RELEASE,
        'amd64_sha512' => AgentCaddyPackage::AMD64_SHA512,
        'minimum_version' => CaddyRelease::MINIMUM,
    ];
    expect(AgentCaddyPackage::input())->toBe($agentInput);
    $node = new Node(['name' => 'dev']);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->once()->with($node, 'caddy.install-package', $agentInput)
        ->andReturn(['changed' => true, 'version' => AgentCaddyPackage::RELEASE]);

    expect((new AgentCaddyPackage($agents))->install($node)->stdout)->toBe(AgentCaddyPackage::Changed."\n");
});
