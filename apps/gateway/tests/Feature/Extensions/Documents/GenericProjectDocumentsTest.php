<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

// Generic Projects (docs/reference/projects.md#generic-projects) about the fixture Nodes nick (8) and mini (11): the Project lookup a
// Conn General thread tags from, the Project Documents each one owns, and the launch eligibility beside a repository Project.
// Body writes refuse to run inside a transaction, so each test runs on its own copy of the migrated database (AgentInstructionsHelpers).
uses(RefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/AgentInstructionsHelpers.php';

beforeEach(function (): void {
    instructionsDatabase($this);
    // The storage keeps each body it is given and answers its read, without a network.
    config(['filesystems.disks.documents.handler' => function (CommandInterface $command): PromiseInterface {
        if ($command->getName() === 'PutObject') {
            $this->bodies[$command['Key']] = (string) $command['Body'];

            return Create::promiseFor(new Result([]));
        }

        return Create::promiseFor(new Result(['Body' => Utils::streamFor($this->bodies[$command['Key']] ?? throw new RuntimeException('No body '.$command['Key']))]));
    }]);
    $this->gateway = genericDocumentsNode(2, 'gateway', '10.44.0.2');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->nick = genericDocumentsNode(8, 'nick', '10.44.1.8');
    $this->mini = genericDocumentsNode(11, 'mini', '10.44.1.11');
    // An operator device that reaches each machine directly, as a Conn client does.
    $this->phone = genericDocumentsNode(40, 'phone', '10.44.1.40');
    $this->phone->accessibleNodes()->attach([$this->nick->id, $this->mini->id]);
});

afterEach(fn () => instructionsDatabaseClosed($this));

function genericDocumentsNode(int $id, string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => tap(new Node()->forceFill([
        'id' => $id, 'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
    ]))->save());
}

/** @param  array<string, mixed>  $body */
function genericDocumentsCall(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/'.$path, $body);
}

/** @return array{nick: int, mini: int} */
function genericDocumentsProjects(object $test): array
{
    $ids = [];
    foreach (['nick' => $test->nick, 'mini' => $test->mini] as $slug => $node) {
        $ids[$slug] = genericDocumentsCall($test->gateway, 'POST', 'projects', ['slug' => $slug, 'type' => 'generic', 'node_id' => $node->id])
            ->assertCreated()->assertJsonPath('data.node_id', $node->id)->json('data.id');
    }

    return $ids;
}

it('resolves nick and mini by the stable ID of their fixture Node through the Project lookup a General thread tags from', function (): void {
    $ids = genericDocumentsProjects($this);
    $this->mini->forceFill(['name' => 'mac-mini'])->save();

    foreach ([8 => $ids['nick'], 11 => $ids['mini']] as $nodeId => $projectId) {
        $found = genericDocumentsCall($this->phone, 'GET', "projects?node_id={$nodeId}")->assertOk()->json('data');
        expect($found)->toHaveCount(1)
            ->and($found[0])->toMatchArray(['id' => $projectId, 'type' => 'generic', 'node_id' => $nodeId, 'repository' => null, 'instance_count' => 0]);
    }
    expect(array_column(genericDocumentsCall($this->phone, 'GET', 'projects')->assertOk()->json('data'), 'slug'))->toBe(['mini', 'nick'])
        ->and(Instance::query()->exists())->toBeFalse();
});

it('writes and reads a document under mini that nick does not have', function (): void {
    $ids = genericDocumentsProjects($this);

    $entry = genericDocumentsCall($this->phone, 'POST', "projects/{$ids['mini']}/documents", [
        'kind' => 'file', 'name' => 'disks.md', 'content_text' => "# mini\nTwo NVMe disks.\n", 'media_type' => 'text/markdown',
    ])->assertCreated()->assertJsonPath('data.project_id', $ids['mini'])->json('data');
    genericDocumentsCall($this->phone, 'PUT', "projects/{$ids['mini']}/documents/{$entry['id']}/content", [
        'expected_revision' => $entry['revision'], 'content_text' => "# mini\nTwo NVMe disks, one spare.\n",
    ])->assertOk()->assertJsonPath('data.current_version.number', 2);

    genericDocumentsCall($this->phone, 'GET', "projects/{$ids['mini']}/documents/{$entry['id']}/content")->assertOk()
        ->assertJsonPath('data.content_text', "# mini\nTwo NVMe disks, one spare.\n")->assertJsonPath('data.version.number', 2);
    expect(array_column(genericDocumentsCall($this->phone, 'GET', "projects/{$ids['mini']}/documents")->assertOk()->json('data'), 'name'))->toBe(['disks.md']);

    // nick has no such document, not in its tree, its search or by the entry's ID.
    genericDocumentsCall($this->phone, 'GET', "projects/{$ids['nick']}/documents")->assertOk()->assertJsonCount(0, 'data');
    genericDocumentsCall($this->phone, 'GET', "projects/{$ids['nick']}/documents/search?q=disks")->assertOk()->assertJsonCount(0, 'data');
    foreach (['', '/content'] as $suffix) {
        genericDocumentsCall($this->phone, 'GET', "projects/{$ids['nick']}/documents/{$entry['id']}{$suffix}")
            ->assertNotFound()->assertJsonPath('error.code', 'project_documents.not_found');
    }
});

it("serves a machine's documents only to a caller that reaches that machine", function (): void {
    $ids = genericDocumentsProjects($this);
    $entry = genericDocumentsCall($this->phone, 'POST', "projects/{$ids['mini']}/documents", ['kind' => 'file', 'name' => 'disks.md', 'content_text' => 'Two NVMe disks.'])
        ->assertCreated()->json('data.id');
    $laptop = genericDocumentsNode(41, 'laptop', '10.44.1.41');
    $laptop->accessibleNodes()->attach($this->nick->id);

    genericDocumentsCall($laptop, 'GET', "projects/{$ids['nick']}/documents")->assertOk();
    foreach (["projects/{$ids['mini']}/documents", "projects/{$ids['mini']}/documents/{$entry}/content"] as $path) {
        genericDocumentsCall($laptop, 'GET', $path)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    }
    expect(array_column(genericDocumentsCall($laptop, 'GET', 'projects')->assertOk()->json('data'), 'slug'))->toBe(['nick']);
});

it('lists a generic Project as not launchable and keeps launching threads in a repository Project', function (): void {
    $ids = genericDocumentsProjects($this);
    $default = instructedDefault();
    $this->phone->accessibleNodes()->attach($default->node_id);

    // Without include_unavailable Conn lists only what a thread can start in.
    expect(array_column(genericDocumentsCall($this->phone, 'GET', 'conn/projects')->assertOk()->json('data'), 'slug'))->toBe(['tool']);
    $listed = collect(genericDocumentsCall($this->phone, 'GET', 'conn/projects?include_unavailable=1')->assertOk()->json('data'))->keyBy('slug');
    expect($listed->keys()->all())->toBe(['mini', 'nick', 'tool'])
        ->and($listed['mini']['checks'][0])->toMatchArray(['key' => 'default_instance', 'status' => 'fail'])
        ->and($listed['tool']['checks'][0])->toMatchArray(['key' => 'default_instance', 'status' => 'pass']);

    genericDocumentsCall($this->phone, 'POST', "conn/projects/{$ids['mini']}/instances", ['name' => 'fix-disk-k3j9'])
        ->assertStatus(409)->assertJsonPath('error.code', 'instance.default_missing');
    genericDocumentsCall($this->gateway, 'POST', 'instances', ['project_id' => $ids['mini'], 'node_id' => $default->node_id, 'name' => 'default'])
        ->assertStatus(409)->assertJsonPath('error.code', 'project.repository_required');
    expect(Operation::query()->where('kind', 'instance.create')->count())->toBe(1);

    $operation = Operation::query()->findOrFail(genericDocumentsCall($this->phone, 'POST', "conn/projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'])
        ->assertAccepted()->json('data.id'));
    expect($operation->kind)->toBe('instance.create')
        ->and($operation->node_id)->toBe($default->node_id)
        ->and(Instance::query()->findOrFail($operation->target_id)->project_id)->toBe($default->project_id)
        ->and(Project::query()->findOrFail($ids['mini'])->instances()->exists())->toBeFalse();
});
