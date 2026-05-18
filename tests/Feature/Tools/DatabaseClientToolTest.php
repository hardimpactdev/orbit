<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\MysqlTool;
use App\Tools\PostgresTool;

it('installs the postgres client with the postgres service tool', function (): void {
    $script = (new PostgresTool)->installScript();

    expect($script)->toContain('postgresql-client')
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'postgres'")
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' up -d 'postgres'");
});

it('installs the mysql client with the mysql service tool', function (): void {
    $script = (new MysqlTool)->installScript();

    expect($script)->toContain('default-mysql-client')
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'mysql'")
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' up -d 'mysql'");
});

it('catalogs sqlite3 as an installable local app utility', function (): void {
    $catalog = app(ToolCatalog::class);

    expect($catalog->supports('sqlite3'))->toBeTrue()
        ->and($catalog->capabilities('sqlite3'))->toContain('install')
        ->and($catalog->installScript('sqlite3'))->toContain('apt-get')
        ->and($catalog->probeMetadata('sqlite3'))->toMatchArray([
            'binary' => 'sqlite3',
            'version_command' => 'sqlite3 --version',
        ]);
});
