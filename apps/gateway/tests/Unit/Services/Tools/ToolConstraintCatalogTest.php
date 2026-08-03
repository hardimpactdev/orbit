<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolDefinitionRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('requires every catalog entry to declare a supported operating system', function (): void {
    $catalog = app(ToolCatalog::class);

    foreach ($catalog->definitions() as $definition) {
        expect($definition->supportedOperatingSystems())
            ->not
            ->toBeEmpty("Tool '{$definition->slug()}' must explicitly declare its supported operating systems.");
    }
});

it('does not infer a generic Linux platform when node platform metadata is missing', function (): void {
    $catalog = app(ToolCatalog::class);

    expect($catalog->operatingSystemForPlatform(null))
        ->toBeNull()
        ->and($catalog->operatingSystemForPlatform(''))
        ->toBeNull()
        ->and($catalog->supportsPlatform('git', null))
        ->toBeFalse();
});

it('does not expose the websocket Reverb runtime as a generic tool definition', function (): void {
    expect(app(ToolDefinitionRegistry::class)->names())->not->toContain('reverb');
});

it('declares Docker-compatible provider requirements for container-backed tools', function (
    string $tool,
    ?string $provider,
    ?string $isolation,
): void {
    $catalog = app(ToolCatalog::class);

    expect($catalog->requiredContainerProvider($tool))
        ->toBe($provider)
        ->and($catalog->isolation($tool))
        ->toBe($isolation);
})->with([
    'caddy' => ['caddy', 'docker-compatible', 'docker'],
    'dns' => ['dns', 'docker-compatible', 'docker-network-namespace'],
    'mailpit' => ['mailpit', 'docker-compatible', 'docker'],
    'php image inventory' => ['php', 'docker-compatible', 'docker'],
    'seaweedfs' => ['seaweedfs', 'docker-compatible', 'docker'],
    'docker provider itself' => ['docker', null, null],
    'hermes host runtime' => ['hermes', null, 'unprivileged-user'],
]);
