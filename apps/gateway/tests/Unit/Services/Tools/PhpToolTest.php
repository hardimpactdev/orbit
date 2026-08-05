<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\PhpTool;
use Tests\TestCase;

uses(TestCase::class);

it('supports registry-only remove without deleting shared runtime images', function (): void {
    $tool = new PhpTool;
    $catalog = app(ToolCatalog::class);

    expect($tool->capabilities())
        ->toContain('remove')
        ->and($tool->removeScript())
        ->toContain('# orbit remove php')
        ->toContain('true')
        ->not->toContain('docker rmi')
        ->not->toContain('docker image rm')->and($catalog->hasCapability(
            'php',
            'remove',
        ))->toBeTrue()->and($catalog->removeScript('php'))->toContain('# orbit remove php');
});
