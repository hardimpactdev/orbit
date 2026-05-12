<?php

declare(strict_types=1);

use App\Contracts\ToolDefinition;
use App\Services\Tools\ToolCatalog;
use App\Tools\PolyscopeServerTool;
use Tests\TestCase;

uses(TestCase::class);

describe('tool catalog definitions', function (): void {
    it('resolves supported tools through dedicated definitions', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definitions())
            ->toHaveCount(count($catalog->names()))
            ->each->toBeInstanceOf(ToolDefinition::class);

        expect($catalog->definition('polyscope-server'))
            ->toBeInstanceOf(PolyscopeServerTool::class);
    });
});
