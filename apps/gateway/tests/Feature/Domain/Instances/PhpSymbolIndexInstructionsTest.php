<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\PhpSymbolIndexRefresh;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Documents\Models\ProjectDocumentEntry;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Orbit\Extensions\Documents\Models\ProjectDocumentUpload;
use Orbit\Extensions\Documents\Models\ProjectDocumentVersion;

require_once __DIR__.'/ContainerInstanceHelpers.php';

// The index refresh of a default whose Project has agent instructions in its Documents. Body reads refuse to run inside a transaction
// (DocumentBodies), so each test leaves the transaction RefreshDatabase opened and runs on its own copy of the migrated database, as
// DocumentsAgentInstructionsTest does.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->databaseFile = tempnam(sys_get_temp_dir(), 'orbit-php-index-test-');
    unlink($this->databaseFile);
    DB::statement('VACUUM INTO ?', [$this->databaseFile]);
    $own = new PDO('sqlite:'.$this->databaseFile);
    DB::connection()->setPdo($own)->setReadPdo($own);

    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('documents', true);
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    workspaceReader();
    // The storage answers each body read by its key, without a network.
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

/** A running Laravel container default, active with a seed. */
function instructedIndexDefault(): Instance
{
    $project = containerDefaultProject(ProjectType::Monorepo, '8.5');
    $project->update(['root' => 'public', 'is_laravel' => true]);
    $create = containerCreate($project, containerDefaultNode());
    $create->update(['status' => 'succeeded', 'lock_key' => null]);
    $default = Instance::query()->findOrFail($create->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

/** A file at `$path` in the Project's Documents with one version. */
function instructedIndexDocument(object $test, int $projectId, string $path, string $body): void
{
    $parent = null;
    $segments = explode('/', $path);
    $name = array_pop($segments);
    foreach ($segments as $folder) {
        $parent = ProjectDocumentEntry::query()->firstOrCreate(['project_id' => $projectId, 'parent_id' => $parent?->id, 'name' => $folder, 'kind' => 'folder'],
            ['sibling_scope' => $parent->id ?? 0, 'revision' => 1]);
    }
    $entry = ProjectDocumentEntry::query()->create(['project_id' => $projectId, 'parent_id' => $parent?->id, 'name' => $name, 'kind' => 'file',
        'sibling_scope' => $parent->id ?? 0, 'revision' => 1]);
    $key = "documents/{$entry->id}/1";
    $upload = ProjectDocumentUpload::query()->create(['project_id' => $projectId, 'entry_id' => $entry->id, 'storage_key' => $key, 'state' => 'published']);
    $version = ProjectDocumentVersion::query()->create(['entry_id' => $entry->id, 'upload_id' => $upload->id, 'number' => 1, 'media_type' => 'text/markdown',
        'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'storage_key' => $key]);
    $entry->update(['current_version_id' => $version->id]);
    $test->bodies[$key] = $body;
}

it('admits the index refresh of a default whose Project has agent instructions, its seed holding them', function (): void {
    $default = instructedIndexDefault();
    // The container intent stamps its bridge admission with the time.
    $this->freezeTime();
    instructedIndexDocument($this, $default->project_id, 'agent/AGENTS.md', "Run the tests.\n");
    instructedIndexDocument($this, $default->project_id, 'agent/.skills/release-notes/SKILL.md', "Write the notes.\n");

    $this->artisan('instances:php-index', ['instance' => [(string) $default->id]])
        ->expectsOutputToContain('"instance":'.$default->id.',"operation":')->assertExitCode(0);

    $operation = Operation::query()->where('kind', PhpSymbolIndexRefresh::Kind)->sole();
    $folder = collect($operation->input['container']['seed_files'])->firstWhere('folder.path', InstanceAgentInstructions::Folder)['folder'];
    expect(array_column($folder['files'], 'path'))->toBe(['AGENTS.md', '.skills/release-notes/SKILL.md'])
        ->and($folder['files'][0]['content'])->toEndWith(InstanceAgentInstructions::ProjectHeading."\n\nRun the tests.\n")
        ->and($folder['files'][1]['content'])->toBe("Write the notes.\n")
        // The same container as the default's deployment would freeze, read outside a transaction.
        ->and($operation->input['container'])->toBe(InstanceDeploymentIntent::container($default->refresh(), $default->runtime()->firstOrFail()));
});

it('admits a default without agent instructions with the input it had before', function (): void {
    $default = instructedIndexDefault();
    // The container intent stamps its bridge admission with the time.
    $this->freezeTime();

    $operation = app(PhpSymbolIndexRefresh::class)->start($default, null, null);

    expect($operation->input)->toBe(['container' => InstanceDeploymentIntent::container($default->refresh(), $default->runtime()->firstOrFail()),
        'seed_commit' => str_repeat('c', 40)])
        ->and(collect($operation->input['container']['seed_files'])->firstWhere('folder.path', InstanceAgentInstructions::Folder)['folder']['files'])
        ->toHaveCount(1);
});
