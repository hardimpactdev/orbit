<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakesEnvironmentSyncInspection;

const SERVER_DATABASE_ROOT_SECRET = 'server-root-secret-5d1e';

trait ServerDatabaseFixtures
{
    protected function prepareServerDatabaseFixtures(): void
    {

        config(['swamp.run_token' => 'fake.token']);
        app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
        $this->admin = new FakeDatabaseServerAdmin;
        app()->instance(DatabaseServerAdmin::class, $this->admin);
        app()->instance(InstanceOperationPreflight::class, new ServerDatabaseEnvironmentAccess);
        app()->instance(InstanceEnvironmentReader::class, new ServerDatabaseEnvironmentAccess);
        app()->instance(InstanceEnvironmentWriter::class, new ServerDatabaseEnvironmentAccess);

        $gateway = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux', 'architecture' => 'amd64',
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'tld' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
        ]);
        $this->gateway = $this->markAsGateway($gateway);
        $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

        $node = Node::query()->create([
            'name' => 'beast',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux', 'architecture' => 'amd64',
            'public_ssh_host' => '192.0.2.80',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.80',
        ]);
        orbit_test_set_app_placement_role($node, false);
        $this->dbNode = $node;
        $process = Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $node->id,
            'name' => 'beast-mysql',
            'runtime' => ProcessRuntime::Docker,
            'working_directory' => '/app',
            'runtime_config' => [
                'image' => 'mysql:8.4',
                'command' => ['mysqld'],
                'environment' => [],
                'ports' => ['10.44.0.80:3306:3306'],
                'volumes' => [],
            ],
            'restart_policy' => 'unless-stopped',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);
        $this->server = DatabaseServer::query()->create([
            'slug' => 'beast-mysql',
            'node_id' => $node->id,
            'process_id' => $process->id,
            'tag' => '8.4',
            'port' => 3306,
            'root_password' => SERVER_DATABASE_ROOT_SECRET,
            'status' => LifecycleStatus::Active,
        ]);

        $project = Project::query()->create([
            'name' => 'DLF',
            'slug' => 'dlf',
            'repository_url' => 'https://example.test/dlf.git',
            'default_branch' => 'main',
        ]);
        $this->instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'feature-x',
            'environment' => 'development',
            'checkout_path' => '/srv/orbit/dlf/feature-x',
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
            'status' => 'active',
        ]);
    }
}

final class ServerDatabaseEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    use FakesEnvironmentSyncInspection;

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }
}
