<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeEnrollmentProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use Mockery;
use Tests\Support\Fleet\FleetFixtures;

final class NativeEnrollmentFixtures
{
    /** @return array{gateway:Node,hub:Node,socket:FakeRoutePublicationSocket} */
    public static function bind(): array
    {
        FleetFixtures::bind();
        $gateway = FleetFixtures::node('admission-gateway', [RoleName::Gateway]);
        $hub = FleetFixtures::node('admission-hub', [RoleName::Vpn]);
        app(VpnSettings::class)->configure('10.44.0.0/24');
        @mkdir(config('orbit.home').'/wireguard', 0o700, true);
        file_put_contents(config('orbit.home').'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
        $signer = Mockery::mock(LeafCertificateSigner::class);
        $signer->shouldReceive('rootCertificate')->andReturn('admitted-public-root');
        app()->instance(LeafCertificateSigner::class, $signer);
        $accounts = Mockery::mock(ManagedUserAccountResolver::class);
        $accounts->shouldNotReceive('resolve');
        app()->instance(ManagedUserAccountResolver::class, $accounts);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldNotReceive('run', 'install');
        app()->instance(AgentCommandRunner::class, $agents);
        app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
        {
            public function definitions(): array
            {
                return ['node.add' => ['workflow' => 'operation-node-add-native', 'workflow_inputs' => true,
                    'terminal_result' => ['job' => 'enrollment', 'step' => 'result'], 'projection' => NodeEnrollmentProjection::class]];
            }
        }]));
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = static function (array $request): array {
            return $request['type'] === 'vault.put'
                ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
                    'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
                : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
                    'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => '08439013-3011-4569-8ee8-374568413e48']]];
        };
        app()->instance(WebSocketClient::class, $socket);
        config(['swamp.run_token' => 'fixture-token']);

        return ['gateway' => $gateway, 'hub' => $hub, 'socket' => $socket];
    }
}
