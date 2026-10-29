<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\TaskVms\RecipeImageInput;
use App\Models\Node;

function recipeInputFixture(): array
{
    return [AgentGuest::incus(new Node(['name' => 'image-host', 'platform' => 'linux', 'architecture' => 'x86_64']), 'default', 'image-builder'), new RecipeLoader()->load()];
}

it('sends the complete base recipe as typed data with the delivery pin and one PHP installer', function (): void {
    [$guest, $recipe] = recipeInputFixture();

    $payload = app(RecipeImageInput::class)->payload($guest, $recipe, 'base');
    expect($payload['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($guest));
    expect($payload['recipe']['vp_lock'])->toBe(file_get_contents(resource_path('machine-software/vite-plus-package-lock.json')));
    expect($payload['php'])->not->toHaveKeys(['mode', 'program', 'bootstrap']);
    expect($payload['php']['fpm'])->toBe('none');
    expect($payload['php']['extras'])->toBe('refuse');
    expect($payload['recipe']['tools'])->not->toHaveKeys(['rust', 'cargo', 'orbit-agent', 'pi', 'php8.4-cli', 'php8.5-cli']);
    expect($payload['rust'])->toHaveKeys(['rust', 'cargo', 'rust-std', 'clippy', 'rustfmt']);
    $document = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['recipe']['architecture'])->toBe('amd64')
        ->and($payload['recipe']['snapshot'])->toBe($document['ubuntu']['snapshot']);
    foreach (RecipeLoader::IncusPackages as $name) {
        expect($payload['recipe']['tools'][$name]['version'])->toBe($document['tools'][$name]['artifacts']['amd64']['version'])
            ->and($payload['recipe']['tools'][$name]['artifacts'])->toBe($document['tools'][$name]['artifacts']);
    }
});

it('installs OpenTelemetry only in app-dev image profiles', function (string $profile, bool $enabled): void {
    [$guest, $recipe] = recipeInputFixture();

    $payload = app(RecipeImageInput::class)->payload($guest, $recipe, $profile);
    expect($payload['php']['opentelemetry'])->toBe($enabled);
    foreach (['8.4', '8.5'] as $version) {
        // The PHP installer owns every PHP package, so the general tool list never carries it.
        expect($payload['recipe']['tools'])->not->toHaveKey("php{$version}-opentelemetry")
            ->and(in_array("php{$version}-opentelemetry", array_column($payload['php']['versions'][$version]['packages'], 'name'), true))->toBe($enabled)
            ->and($payload['php']['versions'][$version]['extensions'])->not->toContain('opentelemetry');
    }
})->with(['app-dev' => ['app-dev', true], 'app-prod' => ['app-prod', false], 'base' => ['base', false], 'ci-runner' => ['ci-runner', false]]);

it('refuses image input from another agent delivery before any phase can start', function (): void {
    [$guest, $recipe] = recipeInputFixture();
    $document = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
    $document['agent']['linux-x86_64'] = str_repeat('e', 64);
    $stale = new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));

    expect(fn () => app(RecipeImageInput::class)->payload($guest, $stale, 'base'))
        ->toThrow(TaskVmException::class, 'stored recipe agent pin differs');
});
