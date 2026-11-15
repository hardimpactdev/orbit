<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectAgentInstructionSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Project;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Documents\Infrastructure\DocumentsAgentInstructions;
use Orbit\Extensions\Documents\Models\ProjectDocumentEntry;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Orbit\Extensions\Documents\Models\ProjectDocumentUpload;
use Orbit\Extensions\Documents\Models\ProjectDocumentVersion;

// Body reads refuse to run inside a transaction (DocumentBodies). Each test rolls back the transaction RefreshDatabase opened on the shared
// in-memory database, copies that migrated database to its own file and runs there without a transaction; nothing it writes reaches
// another test.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->databaseFile = tempnam(sys_get_temp_dir(), 'orbit-documents-test-');
    unlink($this->databaseFile);
    DB::statement('VACUUM INTO ?', [$this->databaseFile]);
    $own = new PDO('sqlite:'.$this->databaseFile);
    DB::connection()->setPdo($own)->setReadPdo($own);
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(ExtensionStore::class)->set('documents', true);
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
    // The storage answers each body read by its key, without a network: the S3 client takes the handler from the disk's configuration.
    $this->bodies = [];
    ProjectDocumentStorage::query()->findOrFail(1)->fill(['endpoint' => 'https://s3.example.test', 'region' => 'auto', 'bucket' => 'docs',
        'access_key_id' => 'key', 'secret_access_key' => 'secret'])->save();
    config(['filesystems.disks.documents.handler' => fn (CommandInterface $command): PromiseInterface => Create::promiseFor(
        new Result(['Body' => Utils::streamFor($this->bodies[$command['Key']] ?? throw new RuntimeException('No body '.$command['Key']))]))]);
});

afterEach(function (): void {
    DB::connection()->setPdo(null)->setReadPdo(null);
    @unlink($this->databaseFile);
});

/** A file at `$path` in the Project's Documents, its folders made as needed, with one version per body. */
function documentFile(object $test, string $path, array $bodies, bool $archived = false, bool $archivedFolder = false): ProjectDocumentEntry
{
    $parent = null;
    $segments = explode('/', $path);
    $name = array_pop($segments);
    foreach ($segments as $index => $folder) {
        $parent = ProjectDocumentEntry::query()->firstOrCreate(['project_id' => $test->project->id, 'parent_id' => $parent?->id, 'name' => $folder, 'kind' => 'folder'],
            ['sibling_scope' => $parent->id ?? 0, 'revision' => 1, 'archived_at' => $archivedFolder && $index === count($segments) - 1 ? now() : null]);
    }
    $entry = ProjectDocumentEntry::query()->create(['project_id' => $test->project->id, 'parent_id' => $parent?->id, 'name' => $name, 'kind' => 'file',
        'sibling_scope' => $parent->id ?? 0, 'revision' => 1, 'archived_at' => $archived ? now() : null]);
    foreach (array_values($bodies) as $index => $body) {
        $key = "documents/{$entry->id}/".($index + 1);
        $upload = ProjectDocumentUpload::query()->create(['project_id' => $test->project->id, 'entry_id' => $entry->id, 'storage_key' => $key, 'state' => 'published']);
        $version = ProjectDocumentVersion::query()->create(['entry_id' => $entry->id, 'upload_id' => $upload->id, 'number' => $index + 1, 'media_type' => 'text/markdown',
            'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'storage_key' => $key]);
        $entry->update(['current_version_id' => $version->id]);
        $test->bodies[$key] = $body;
    }

    return $entry;
}

it('is the production source of the agent instructions', function (): void {
    expect(iterator_to_array(app()->tagged(ProjectAgentInstructionSource::class)))->toHaveCount(1)
        ->and(iterator_to_array(app()->tagged(ProjectAgentInstructionSource::class))[0])->toBeInstanceOf(DocumentsAgentInstructions::class);
});

it('reads the current version of each active file below the agent/ folder at the root, and no other file', function (): void {
    documentFile($this, 'agent/AGENTS.md', ["Old.\n", "Run the tests.\n"]);
    documentFile($this, 'agent/.skills/release-notes/SKILL.md', ["Write the notes.\n"]);
    documentFile($this, 'agent/.skills/old/SKILL.md', ['gone'], archived: true);
    documentFile($this, 'agent/.skills/hidden/SKILL.md', ['gone'], archivedFolder: true);
    documentFile($this, 'specs/agent/AGENTS.md', ['not at the root']);

    expect(app(InstanceAgentInstructions::class)->read($this->project))->toBe([
        '.skills/release-notes/SKILL.md' => "Write the notes.\n",
        'AGENTS.md' => "Run the tests.\n",
    ])
        ->and(app(InstanceAgentInstructions::class)->summary($this->project))->toBe(['source' => 'project', 'agents_md_version' => 2, 'skills' => ['release-notes']]);
});

it('has nothing for a Project without the folder, for an archived folder, and while the extension is off', function (): void {
    expect(app(InstanceAgentInstructions::class)->read($this->project))->toBe([])
        ->and(app(InstanceAgentInstructions::class)->summary($this->project))->toBe(['source' => 'type_note', 'agents_md_version' => null, 'skills' => []]);

    documentFile($this, 'agent/AGENTS.md', ["Run the tests.\n"]);
    app(ExtensionStore::class)->set('documents', false);
    expect(app(InstanceAgentInstructions::class)->read($this->project))->toBe([]);

    app(ExtensionStore::class)->set('documents', true);
    ProjectDocumentEntry::query()->where('name', 'agent')->update(['archived_at' => now()]);
    expect(app(InstanceAgentInstructions::class)->read($this->project))->toBe([]);
});

it('fails with the store\'s own error when a body is missing', function (): void {
    documentFile($this, 'agent/AGENTS.md', ["Run the tests.\n"]);
    $this->bodies = [];

    expect(fn () => app(InstanceAgentInstructions::class)->read($this->project))->toThrow(ResourceOperationException::class);
});

it('shows on project:show what the next copies read', function (): void {
    $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk()
        ->assertJsonPath('data.agent_instructions', ['source' => 'type_note', 'agents_md_version' => null, 'skills' => []]);
    documentFile($this, 'agent/AGENTS.md', ['One', 'Two', 'Three']);
    documentFile($this, 'agent/.skills/zeta/SKILL.md', ['z']);
    documentFile($this, 'agent/.skills/alpha/SKILL.md', ['a']);

    $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk()
        ->assertJsonPath('data.agent_instructions', ['source' => 'project', 'agents_md_version' => 3, 'skills' => ['alpha', 'zeta']]);
});
