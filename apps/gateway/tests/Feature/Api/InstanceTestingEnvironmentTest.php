<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakesEnvironmentSyncInspection;

beforeEach(function (): void {
    $this->environment = new TestingEnvironmentAccess;
    app()->instance(InstanceOperationPreflight::class, $this->environment);
    app()->instance(InstanceEnvironmentReader::class, $this->environment);
    app()->instance(InstanceEnvironmentWriter::class, $this->environment);
    app()->instance(InstanceTestEnvironmentWriter::class, $this->environment);
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

/** @return array<string, string> */
function testing_environment_values(string $contents): array
{
    $values = [];

    foreach (array_filter(explode("\n", $contents)) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = trim($value, '"');
    }

    return $values;
}

/** Stored configuration after a database clone; this fixture performs no Node work. */
function testing_environment_fixture(Project $project, Node $node): Instance
{
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature-x',
        'checkout_path' => '/srv/orbit/apps/acme/feature-x', 'source_is_laravel' => true, 'selected_php_version' => '8.5', 'provisioning_step' => 'active',
        'status' => InstanceState::Active]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'generation_basis_node_id' => $node->id,
        'domain' => 'feature-x.acme.test', 'publication' => 'private', 'provenance' => 'generated', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $connection = DatabaseConnection::query()->create([
        'slug' => 'acme-feature-x', 'driver' => 'mysql', 'owner_instance_id' => $instance->id,
        'node_id' => $node->id, 'host' => '10.44.0.3', 'port' => 3306,
        'database' => 'acme_feature_x', 'test_database' => 'acme_feature_x_test',
        'username' => 'acme_feature_x', 'password' => 'synthetic-password',
    ]);
    DatabaseConnectionTarget::query()->create([
        'instance_id' => $instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB',
    ]);
    foreach (['APP_KEY' => 'base64:kept', 'APP_URL' => 'http://localhost', 'DB_CONNECTION' => 'sqlite'] as $key => $value) {
        InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => $key, 'env_value' => $value]);
    }

    return $instance;
}

describe('.env.testing on synchronization', function (): void {
    // The writer seeds a missing file with these contents and merges only the managed keys into an existing one.
    it('sends the full .env.testing seed with the test database and records the test database', function (): void {
        $instance = testing_environment_fixture($this->project, $this->node);
        $this->environment->testingContents = null;

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertOk()
            ->assertJsonMissingPath('data.testing');

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect(testing_environment_values((string) $this->environment->testingContents))->toMatchArray([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:kept',
            'DB_DATABASE' => 'acme_feature_x_test',
            'DB_HOST' => '10.44.0.3',
        ])
            ->and($this->environment->testingManagedKeys)->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
            ->and($activity->properties?->toArray()['testing'] ?? null)->toBe([
                'file' => '.env.testing',
                'status' => 'written',
                'test_database' => 'acme_feature_x_test',
            ]);
    });

    it('never writes a .env.testing that Git tracks and records the test database instead', function (): void {
        $this->environment->testingTracked = true;
        $instance = testing_environment_fixture($this->project, $this->node);

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertOk();

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($this->environment->testingContents)->toBeNull()
            ->and($this->environment->contents)->not->toBeNull()
            ->and($activity->properties?->toArray()['testing'] ?? null)->toBe([
                'file' => '.env.testing',
                'status' => 'skipped_tracked',
                'test_database' => 'acme_feature_x_test',
            ]);
    });
});

final class TestingEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight, InstanceTestEnvironmentWriter
{
    use FakesEnvironmentSyncInspection;

    public ?string $contents = null;

    public ?string $testingContents = null;

    /** @var list<string> */
    public array $testingManagedKeys = [];

    public bool $testingTracked = false;

    public int $reads = 0;

    public bool $missing = false;

    public string $source = "APP_KEY=base64:kept\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\n";

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}

    public function read(InstanceEnvironmentContext $context): string
    {
        $this->reads++;

        if ($this->missing) {
            throw new ResourceOperationException('env.import_source_missing', 'The recorded Instance environment file does not exist.', 404);
        }

        return $this->contents ?? $this->source;
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        $this->contents = $contents;

        return InstanceEnvironmentWriteResult::changed();
    }

    public function mergeTesting(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents, array $managedKeys): InstanceEnvironmentWriteResult
    {
        $this->testingManagedKeys = $managedKeys;

        if ($this->testingTracked) {
            return InstanceEnvironmentWriteResult::tracked();
        }

        $this->testingContents = $contents;

        return InstanceEnvironmentWriteResult::changed();
    }
}
