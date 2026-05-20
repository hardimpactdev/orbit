<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\MysqlTool;
use App\Tools\PostgresTool;

it('postgres service tool no longer installs the psql client binary', function (): void {
    $script = (new PostgresTool)->installScript();

    expect($script)->not->toContain('postgresql-client')
        ->and($script)->not->toContain('apt-get')
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'postgres'")
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' up -d 'postgres'");
});

it('mysql service tool no longer installs the mysql client binary', function (): void {
    $script = (new MysqlTool)->installScript();

    expect($script)->not->toContain('default-mysql-client')
        ->and($script)->not->toContain('apt-get')
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'mysql'")
        ->and($script)->toContain("docker compose -f '/opt/orbit/docker-compose.yml' up -d 'mysql'");
});

it('sqlite3 is no longer in the tool catalog', function (): void {
    $catalog = app(ToolCatalog::class);

    expect($catalog->supports('sqlite3'))->toBeFalse();
});
