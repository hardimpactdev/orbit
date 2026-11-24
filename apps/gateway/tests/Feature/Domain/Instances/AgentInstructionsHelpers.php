<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Documents\Models\ProjectDocumentEntry;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Orbit\Extensions\Documents\Models\ProjectDocumentUpload;
use Orbit\Extensions\Documents\Models\ProjectDocumentVersion;

require_once __DIR__.'/ContainerInstanceHelpers.php';

// A default whose Project has agent instructions in its Documents. Body reads refuse to run inside a transaction (DocumentBodies),
// so a test leaves the transaction RefreshDatabase opened and runs on its own copy of the migrated database, as
// DocumentsAgentInstructionsTest does.

/** Leaves RefreshDatabase's transaction for an own copy of the database, with the Documents storage answering each body by its key. */
function instructionsDatabase(object $test): void
{
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $test->databaseFile = tempnam(sys_get_temp_dir(), 'orbit-instructions-test-');
    unlink($test->databaseFile);
    DB::statement('VACUUM INTO ?', [$test->databaseFile]);
    $own = new PDO('sqlite:'.$test->databaseFile);
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
    $test->bodies = [];
    ProjectDocumentStorage::query()->findOrFail(1)->fill(['endpoint' => 'https://s3.example.test', 'region' => 'auto', 'bucket' => 'docs',
        'access_key_id' => 'key', 'secret_access_key' => 'secret'])->save();
    config(['filesystems.disks.documents.handler' => fn (CommandInterface $command): PromiseInterface => Create::promiseFor(
        new Result(['Body' => Utils::streamFor($test->bodies[$command['Key']] ?? throw new RuntimeException('No body '.$command['Key']))]))]);
}

function instructionsDatabaseClosed(object $test): void
{
    DB::connection()->setPdo(null)->setReadPdo(null);
    @unlink($test->databaseFile);
}

/** A running Laravel container default, active with a seed. */
function instructedDefault(): Instance
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
function instructedDocument(object $test, int $projectId, string $path, string $body): void
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
