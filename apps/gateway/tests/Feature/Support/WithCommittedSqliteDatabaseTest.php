<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Tests\Support\WithCommittedSqliteDatabase;

uses(WithCommittedSqliteDatabase::class);

it('copies pristine migrated databases with real commits without replacing the RefreshDatabase transaction', function (): void {
    $home = sys_get_temp_dir().'/committed-database-test-'.bin2hex(random_bytes(8));
    mkdir($home, 0700);
    $this->beforeApplicationDestroyed(static function () use ($home): void {
        DB::disconnect();
        File::deleteDirectory($home);
    });
    $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    $original = DB::connection('sqlite')->getPdo();

    $this->useCommittedSqliteDatabase($home.'/first.sqlite');

    expect(DB::transactionLevel())->toBe(0);
    expect(DB::getPdo()->inTransaction())->toBeFalse();
    expect(DB::table('migrations')->orderBy('migration')->pluck('migration')->all())->toBe($migrations);
    expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
    DB::transaction(static function (): void {
        Project::query()->create(['name' => 'Committed', 'slug' => 'committed', 'repository_url' => 'https://example.test/committed.git']);
    });
    $independent = new PDO('sqlite:'.$home.'/first.sqlite');
    expect($independent->query('SELECT slug FROM projects')->fetchColumn())->toBe('committed');
    DB::beginTransaction();
    DB::table('projects')->delete();
    DB::rollBack();
    expect(Project::query()->count())->toBe(1);
    DB::statement('CREATE TABLE fixture_mutation (id INTEGER)');
    ProjectDocumentStorage::query()->findOrFail(1)->update(['endpoint' => 'https://changed.example.test']);

    $this->useCommittedSqliteDatabase($home.'/second.sqlite');

    expect(DB::table('migrations')->orderBy('migration')->pluck('migration')->all())->toBe($migrations);
    expect(Project::query()->count())->toBe(0);
    expect(Schema::hasTable('fixture_mutation'))->toBeFalse();
    expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
    expect(DB::connection('sqlite')->getPdo())->toBe($original);
    expect(DB::connection('sqlite')->transactionLevel())->toBe(1);
    expect($original->inTransaction())->toBeTrue();
});
