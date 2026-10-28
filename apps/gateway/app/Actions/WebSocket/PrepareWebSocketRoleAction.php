<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Actions\Integrations\StartIntegrationOperationAction;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Integrations\IntegrationPlan;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketHostname;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Renders the pinned Reverb intent without starting or restarting any process. */
final readonly class PrepareWebSocketRoleAction
{
    public function __construct(private StartIntegrationOperationAction $operations, private WebSocketCredentialManager $credentials,
        private ManagedUserAccountResolver $accounts, private RolePrerequisites $prerequisites, private GatewayCertificateIssuer $certificates,
        private NodeCaddyfileRenderer $caddy, private WebSocketEnvironmentRenderer $environment, private WebSocketServiceUnitRenderer $unit) {}

    /** @param array{assignment_id: int, role: string, source_node_id: int, target_node_id: int|null, created: bool} $role */
    public function execute(Operation $operation, array $role): Operation
    {
        return DB::transaction(function () use ($operation, $role): Operation {
            $node = Node::query()->findOrFail($operation->node_id);
            $address = ValidatedData::string($node->wireguard_ip);
            if ($node->platform !== 'linux' || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('websocket.publication_address_invalid', 'WebSocket role effects need a managed Linux Node with a WireGuard IPv4 address.', 409);
            }
            $remove = $operation->kind === 'websocket.role_remove';
            $offline = $remove && $operation->input['offline'] === true;
            $plan = new IntegrationPlan($node->id);
            $input = ['node_id' => $node->id, 'assignment_id' => $role['assignment_id'], 'created' => $role['created'], 'offline' => $offline];
            $dns = NodeRole::query()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->sole();
            $input['dns_node_id'] = $dns->node_id;
            if ($remove) {
                $plan->gateway('websocket-certificate-withdraw', 'websocket.publication-state', ['action' => 'certificate-withdraw']);
                if (! $offline) {
                    $plan->child('websocket-withdraw', 'route.caddy_converge', $node->id, ['reason' => 'websocket withdrawal']);
                    $plan->agent('websocket-certificate-remove', 'caddy.remove-certificate', ['directory' => WebSocketFootprint::CertificateCurrentDirectory]);
                }
                $plan->child('websocket-withdraw-dns', 'route.private_dns_publish', $dns->node_id, ['reason' => 'websocket withdrawal']);
                if (! $offline) {
                    $plan->agent('websocket-runtime-remove', 'websocket.remove-runtime', ['install_path' => ValidatedData::string(config('orbit.websocket.install_path')), 'purge' => $operation->input['purge_data']]);
                }
                $plan->gateway('websocket-retire', 'websocket.publication-state', ['action' => 'retired']);
            } else {
                $rendered = $this->caddy->render($node);
                if (! $rendered->buildable()) {
                    throw new ResourceOperationException('websocket.caddy_publication_failed', 'The Node Caddy listeners cannot serve the admitted WebSocket role.', 409);
                }
                if ($rendered->listenAddresses !== []) {
                    $plan->agent('websocket-listen-check', 'caddy.check-addresses', ['listen_addresses' => $rendered->listenAddresses]);
                }
                $account = $this->accounts->resolve($node);
                $credentials = $this->credentials->ensure($node);
                $plan->agent('websocket-caddy-package', 'caddy.install-package', AgentCaddyPackage::input());
                $plan->agent('websocket-prerequisites', 'role.install-prerequisites', $this->prerequisites->input($node, RoleName::WebSocket, $account));
                $port = ValidatedData::integer(config('orbit.websocket.port'));
                $path = ValidatedData::string(config('orbit.websocket.install_path'));
                $plan->agent('websocket-runtime', 'websocket.converge-runtime', ['user' => $account->user, 'group' => $account->group, 'install_path' => $path,
                    'repository' => ValidatedData::string(config('orbit.websocket.repository')), 'ref' => ValidatedData::string(config('orbit.websocket.ref')), 'port' => $port,
                    'unit' => $this->unit->render($path, $account->user, $account->group, $port)], ['environment' => $this->environment->render($credentials, $port)]);
                $plan->agent('websocket-health', 'websocket.runtime-active', []);
                $certificate = $this->certificates->issue(WebSocketHostname::Value, $address);
                $plan->agent('websocket-certificate', 'caddy.install-certificate', ['directory' => WebSocketFootprint::CertificateCurrentDirectory,
                    'certificate_file' => 'reverb.pem', 'key_file' => 'reverb.key', 'service' => 'caddy'],
                    ['certificate' => $this->read($certificate->certificatePath), 'key' => $this->read($certificate->privateKeyPath)]);
                $plan->gateway('websocket-certificate-record', 'integration.certificate-record', ['slug' => 'websocket']);
                $plan->child('websocket-publication', 'route.caddy_converge', $node->id, ['reason' => 'websocket publication']);
                $plan->gateway('websocket-serving', 'websocket.publication-state', ['action' => 'serving']);
                $plan->child('websocket-dns', 'route.private_dns_publish', $dns->node_id, ['reason' => 'websocket publication']);
            }
            $plan->receipt($remove ? 'absent' : 'active');
            if (! $remove && $role['created']) {
                $count = count($plan->flow);
                $plan->agent('websocket-rollback-stop', 'websocket.remove-runtime', ['install_path' => $path, 'purge' => false]);
                $plan->gateway('websocket-rollback-certificate-withdraw', 'websocket.publication-state', ['action' => 'certificate-withdraw']);
                $plan->child('websocket-rollback-caddy', 'route.caddy_converge', $node->id, ['reason' => 'websocket new assignment rollback']);
                $plan->agent('websocket-rollback-certificate-remove', 'caddy.remove-certificate', ['directory' => WebSocketFootprint::CertificateCurrentDirectory]);
                $plan->gateway('websocket-rollback-retire', 'websocket.publication-state', ['action' => 'retired']);
                $plan->child('websocket-rollback-dns', 'route.private_dns_publish', $dns->node_id, ['reason' => 'websocket new assignment rollback']);
                $input['failure_flow'] = array_splice($plan->flow, $count);
                $input['failure_when'] = ['step' => 'websocket-runtime', 'field' => 'applied', 'equals' => true];
                foreach ($plan->effects as $key => $effect) {
                    if (str_starts_with($key, 'websocket-rollback-')) {
                        $plan->effects[$key]['required'] = false;
                    }
                }
            }

            return $this->operations->prepare($operation, $input, $plan);
        });
    }

    private function read(string $path): string
    {
        $value = file_get_contents($path);
        if (! is_string($value) || $value === '') {
            throw new ResourceOperationException('websocket.certificate_read_failed', 'The recorded WebSocket certificate is unreadable.', 422);
        }

        return $value;
    }
}
