<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
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
    'reverb' => ['reverb', 'docker-compatible', 'docker'],
    'seaweedfs' => ['seaweedfs', 'docker-compatible', 'docker'],
    'docker provider itself' => ['docker', null, null],
    'openclaw host runtime' => ['openclaw', null, 'unprivileged-user'],
    'hermes host runtime' => ['hermes', null, 'unprivileged-user'],
]);
