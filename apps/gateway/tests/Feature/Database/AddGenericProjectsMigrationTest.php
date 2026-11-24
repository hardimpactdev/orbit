<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function genericProjectsMigration(): object
{
    return require database_path('migrations/2027_03_25_000000_add_generic_projects.php');
}

function genericProjectsMigrationProject(string $slug, string $code): int
{
    return (int) DB::table('projects')->insertGetId([
        'name' => $slug, 'slug' => $slug, 'code' => $code,
        'repository_url' => "https://github.com/acme/{$slug}.git", 'repository_identity' => "github.com/acme/{$slug}",
    ]);
}

it('rolls back with repository Projects, drops node_id, and keeps the Project code guards and the other indexes', function (): void {
    genericProjectsMigrationProject('alpha', 'ALP');
    genericProjectsMigrationProject('beta', 'BET');

    genericProjectsMigration()->down();

    $objects = array_column(DB::select("SELECT name FROM sqlite_master WHERE tbl_name = 'projects' AND sql IS NOT NULL"), 'name');
    expect(Schema::hasColumn('projects', 'node_id'))->toBeFalse()
        ->and($objects)->toEqualCanonicalizing(['projects', 'projects_code_unique', 'projects_repository_identity_unique',
            'projects_slug_unique', 'projects_code_insert', 'projects_code_update'])
        ->and(fn () => genericProjectsMigrationProject('gamma', 'bad'))->toThrow(QueryException::class, 'Project code must contain three uppercase letters')
        ->and(fn () => DB::table('projects')->where('slug', 'alpha')->update(['code' => 'no']))->toThrow(QueryException::class, 'Project code must contain three uppercase letters');

    genericProjectsMigration()->up();

    expect(Schema::hasColumn('projects', 'node_id'))->toBeTrue()
        ->and(fn () => genericProjectsMigrationProject('gamma', 'bad'))->toThrow(QueryException::class, 'Project code must contain three uppercase letters');
});

it('keeps the Project ID sequence past a deleted highest Project in up and in down', function (): void {
    genericProjectsMigrationProject('alpha', 'ALP');
    $deleted = genericProjectsMigrationProject('beta', 'BET');
    DB::table('projects')->delete($deleted);

    genericProjectsMigration()->down();
    $afterDown = genericProjectsMigrationProject('gamma', 'GAM');
    DB::table('projects')->delete($afterDown);

    genericProjectsMigration()->up();
    $afterUp = genericProjectsMigrationProject('delta', 'DEL');

    expect($afterDown)->toBeGreaterThan($deleted)
        ->and($afterUp)->toBeGreaterThan($afterDown);
});

it('holds one write transaction across the rebuild, so a concurrent writer cannot commit a change it would lose', function (): void {
    genericProjectsMigration()->down();

    // A second connection needs a database file: the tables the migration touches, `projects` and the `nodes` it references,
    // with their indexes and triggers, and one Project are copied to one in one transaction, and the migration runs there.
    $file = tempnam(sys_get_temp_dir(), 'generic-projects-');
    $copy = new PDO("sqlite:{$file}");
    $copy->beginTransaction();
    foreach (DB::select("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND tbl_name IN ('projects', 'nodes') ORDER BY rowid") as $object) {
        $copy->exec($object->sql);
    }
    $copy->prepare('INSERT INTO projects (name, slug, code, repository_url, repository_identity) VALUES (?, ?, ?, ?, ?)')
        ->execute(['alpha', 'alpha', 'ALP', 'https://github.com/acme/alpha.git', 'github.com/acme/alpha']);
    $copy->commit();
    $copy = null;
    config(['database.connections.generic_projects_file' => [...config('database.connections.sqlite'), 'database' => $file]]);
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('generic_projects_file');

    $writer = new PDO("sqlite:{$file}");
    $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $writer->setAttribute(PDO::ATTR_TIMEOUT, 0);
    $blocked = null;
    DB::listen(function ($query) use ($writer, &$blocked): void {
        if ($blocked === null && str_starts_with($query->sql, 'insert into "__temp__projects"')) {
            try {
                $writer->exec("UPDATE projects SET name = 'renamed' WHERE slug = 'alpha'");
                $blocked = false;
            } catch (PDOException $exception) {
                $blocked = str_contains($exception->getMessage(), 'database is locked');
            }
        }
    });

    try {
        genericProjectsMigration()->up();
        $name = DB::table('projects')->where('slug', 'alpha')->value('name');
    } finally {
        DB::disconnect('generic_projects_file');
        DB::setDefaultConnection($default);
        $writer = null;
        @unlink($file);
    }

    expect($blocked)->toBeTrue()->and($name)->toBe('alpha');
});

it('refuses to roll back while a generic Project exists, and leaves the table as it was', function (): void {
    DB::table('projects')->insert(['name' => 'mini', 'slug' => 'mini', 'code' => 'MIN', 'type' => 'generic']);

    expect(fn () => genericProjectsMigration()->down())->toThrow(RuntimeException::class, 'Remove every generic Project before rolling back')
        ->and(Schema::hasColumn('projects', 'node_id'))->toBeTrue()
        ->and(DB::table('projects')->where('slug', 'mini')->exists())->toBeTrue()
        ->and(DB::scalar('PRAGMA foreign_keys'))->toBe(1);
});
