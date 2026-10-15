<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function documents_default_migration(): Migration
{
    $migration = require dirname(__DIR__, 5).'/extensions/documents/database/migrations/2026_11_09_000000_enable_documents_extension_for_existing_documents.php';
    expect($migration)->toBeInstanceOf(Migration::class);

    return $migration;
}

beforeEach(function (): void {
    // The directory's setup turns the switch on; each case starts from an install that never set it.
    app(ExtensionStore::class)->set('documents', false);
});

it('leaves documents off on an install without Documents state', function (): void {
    documents_default_migration()->up();

    expect(app(ExtensionStore::class)->enabled('documents'))->toBeFalse();
});

it('turns documents on for an install with configured document storage', function (): void {
    DB::table('project_document_storages')->where('id', 1)->update(['endpoint' => 'https://s3.example.test']);

    documents_default_migration()->up();

    expect(app(ExtensionStore::class)->enabled('documents'))->toBeTrue();
});

it('turns documents on for an install with a document entry', function (): void {
    $project = Project::query()->create(['name' => 'Notes', 'slug' => 'notes', 'repository_url' => 'https://example.test/notes.git']);
    DB::table('project_document_entries')->insert([
        'project_id' => $project->id, 'kind' => 'folder', 'name' => 'Specs', 'sibling_scope' => 0,
        'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    documents_default_migration()->up();
    expect(app(ExtensionStore::class)->enabled('documents'))->toBeTrue();

    documents_default_migration()->down();
    expect(app(ExtensionStore::class)->enabled('documents'))->toBeFalse();
});
