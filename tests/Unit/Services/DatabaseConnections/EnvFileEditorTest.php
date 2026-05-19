<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\EnvFileEditor;
use Tests\TestCase;

uses(TestCase::class);

describe('EnvFileEditor', function (): void {
    it('parses quoted and unquoted values', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = <<<'ENV'
APP_NAME=Orbit
DB_PASSWORD="p@ss word"
DB_USERNAME='orbit\'user'
DB_DATABASE=/srv/apps/orbit/database.sqlite
EMPTY_VALUE=
ENV;

        expect($editor->parse($contents))->toBe([
            'APP_NAME' => 'Orbit',
            'DB_PASSWORD' => 'p@ss word',
            'DB_USERNAME' => "orbit'user",
            'DB_DATABASE' => '/srv/apps/orbit/database.sqlite',
            'EMPTY_VALUE' => '',
        ]);
    });

    it('updates existing keys and appends missing keys while preserving comments and unrelated lines', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = <<<'ENV'
# Application
APP_NAME=Orbit
DB_HOST=127.0.0.1
DB_PASSWORD='old-password'

# Keep this comment
QUEUE_CONNECTION=database
ENV;

        $updated = $editor->update($contents, [
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '5432',
            'DB_PASSWORD' => 'new password',
        ]);

        expect($updated)->toBe(<<<'ENV'
# Application
APP_NAME=Orbit
DB_HOST=db.internal
DB_PASSWORD='new password'

# Keep this comment
QUEUE_CONNECTION=database
DB_PORT=5432
ENV);
    });
});
