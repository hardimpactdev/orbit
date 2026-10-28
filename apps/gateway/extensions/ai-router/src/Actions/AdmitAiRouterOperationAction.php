<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Integrations\StartIntegrationOperationAction;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Integrations\IntegrationPlan;
use App\Domain\Integrations\IntegrationProcessIntent;
use App\Domain\Integrations\IntegrationSecretSource;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostnameRoute;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprint;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

/** Gateway-owned Process and publication intent; Swamp owns its order and effects. */
final readonly class AdmitAiRouterOperationAction
{
    public function __construct(private AiRouterState $state, private AiRouterPlacement $placement, private AiRouterCollectorEnvironment $environment,
        private IntegrationProcessIntent $processes, private StartIntegrationOperationAction $operations, private GatewayCertificateIssuer $certificates,
        private AgentDelivery $delivery, private AiRouterHostnameRoute $routes, private IntegrationSecretSource $secrets) {}

    public function execute(string $kind = 'ai_router.collector_converge', string $trigger = 'operator'): Operation
    {
        return $this->build($kind, $trigger);
    }

    public function prepare(Operation $operation): Operation
    {
        if ($operation->kind !== 'ai_router.collector_converge' || $operation->input !== [] || ! $this->state->enabled()
            || $operation->node_id !== $this->state->nodeId()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector preparation has another owner.', 422);
        }
        if (isset($operation->state['integration_intent'])) {
            return $operation;
        }

        return $this->build($operation->kind, 'integration', $operation);
    }

    private function build(string $kind, string $trigger, ?Operation $existing = null): Operation
    {
        return DB::transaction(function () use ($kind, $trigger, $existing): Operation {
            if ($kind === 'ai_router.teardown' && $this->state->nodeId() === null) {
                $gateway = app(NodeAccessAuthorizer::class)->activeGatewayId()
                    ?? throw new ResourceOperationException('ai_router.node_invalid', 'Teardown needs one recorded Gateway Node.', 409);
                $this->state->disable();
                $plan = new IntegrationPlan($gateway);
                $plan->receipt('absent');

                return $this->operations->execute($kind, 'ai_router', $gateway, $gateway, ['noop' => true], $plan, $trigger);
            }
            $nodeId = $this->state->nodeId() ?? throw new ResourceOperationException('ai_router.node_invalid', 'The collector has no recorded Node.', 409);
            $node = $this->placement->node($nodeId);
            $plan = new IntegrationPlan($nodeId);
            $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', AiRouterProcess::NAME)->first();
            $input = ['node_id' => $nodeId, 'process_id' => $process?->id];
            $cutover = DB::table('ai_router_cutovers')->where('node_id', $nodeId)->first();
            if ($cutover !== null) {
                $retired = Process::query()->whereKey($cutover->process_id)->first();
                if ($retired !== null) {
                    if ($retired->owner_type !== Node::class || $retired->owner_id !== $nodeId || $retired->name !== 'cli-proxy-api-collector') {
                        throw new ResourceOperationException('ai_router.cutover_owner_changed', 'The retired collector owner changed.', 409);
                    }
                    $plan->child('collector-retired-process', 'process.destroy', $nodeId, $this->processes->remove($retired));
                }
                $plan->gateway('collector-cache-rename', 'ai_router.rename-cache', []);
                $input['cutover'] = true;
            }
            if ($kind === 'ai_router.teardown') {
                if ($process !== null) {
                    $plan->child('collector-remove', 'process.destroy', $nodeId, $this->processes->remove($process));
                }
                $this->state->disable();
                $plan->child('collector-withdraw', 'route.caddy_converge', $nodeId, ['reason' => 'ai-router teardown']);
                $plan->agent('collector-certificate-remove', 'caddy.remove-certificate', ['directory' => AiRouterFootprint::CertificateCurrentDirectory]);
                $plan->agent('collector-source-remove', 'ai-router.remove-source', ['retired' => false]);
            } else {
                $connection = $this->placement->connection($this->state->cacheConnection() ?? '');
                $takeover = $this->routes->takeoverCandidate($node);
                if ($takeover !== null) {
                    $input['takeover'] = ['id' => $takeover->id, 'status' => $takeover->status->value, 'sites_published' => $takeover->sites_published];
                    $takeover->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
                }
                $intent = $this->processes->converge($node, AiRouterProcess::data($node, $this->environment->build($connection)));
                $process = $intent['process'];
                $input['process_id'] = $process->id;
                $input['source_revision'] = $this->delivery->pin($node);
                AiRouterProcessSource::query()->updateOrCreate(['process_id' => $process->id], ['source_revision' => null, 'activation_pending' => true]);
                $certificate = $this->certificates->issue(AiRouterHostname::Value, ValidatedData::string($node->wireguard_ip));
                $plan->agent('collector-caddy-package', 'caddy.install-package', AgentCaddyPackage::input());
                $plan->agent('collector-certificate', 'caddy.install-certificate', [
                    'directory' => AiRouterFootprint::CertificateCurrentDirectory, 'certificate_file' => 'ai_router.pem', 'key_file' => 'ai_router.key', 'service' => 'caddy',
                ], ['certificate' => $this->read($certificate->certificatePath), 'key' => $this->read($certificate->privateKeyPath)]);
                $plan->gateway('certificate-record', 'integration.certificate-record', ['slug' => AiRouterCaddySiteSource::CertificateSlug]);
                $plan->child('collector-publication', 'route.caddy_converge', $nodeId, ['reason' => 'ai-router collector publication']);
                $plan->agent('collector-source-remove', 'ai-router.remove-source', ['retired' => false]);
                $plan->child('collector-process', $intent['kind'], $nodeId, $intent['input']);
                $plan->agent('collector-health', 'http.await-healthy', ['url' => 'http://127.0.0.1:8787/health', 'attempts' => 30, 'interval_seconds' => 1]);
                if ($takeover !== null) {
                    $plan->child('collector-takeover-remove', 'route.destroy', $nodeId, ['route_id' => $takeover->id, 'offline' => false]);
                }
            }
            if ($cutover !== null) {
                $plan->agent('collector-retired-source', 'ai-router.remove-source', ['retired' => true]);
                $plan->agent('collector-retired-certificate', 'caddy.remove-certificate', ['directory' => '/etc/caddy/orbit-proxycli-cert-current']);
            }
            $vpn = NodeRole::query()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->sole();
            $input['dns_node_id'] = $vpn->node_id;
            $plan->child('collector-dns', 'route.private_dns_publish', $vpn->node_id, ['reason' => 'ai-router collector intent']);
            $plan->receipt($kind === 'ai_router.teardown' ? 'absent' : 'active');
            if (isset($input['takeover'])) {
                $plan->gateway('collector-takeover-restore', 'ai_router.restore-takeover', []);
                array_pop($plan->flow);
                $plan->effects['collector-takeover-restore']['required'] = false;
                $input['failure_recovery'] = ['before_child' => 'collector-publication', 'effect' => 'collector-takeover-restore',
                    'child' => ['key' => 'collector-takeover-republish', 'kind' => 'route.caddy_converge', 'node_id' => $nodeId,
                        'input' => ['reason' => 'ai-router takeover restoration']]];
            }

            if ($existing !== null) {
                $existing->state = [...($existing->state ?? []), 'integration_intent' => [...$input, 'effects' => $plan->effects, 'flow' => $plan->flow]];
                $existing->save();
                $this->secrets->store($existing, $plan->protected);

                return $existing;
            }

            return $this->operations->execute($kind, 'ai_router', $nodeId, $nodeId, $input, $plan, $trigger);
        });
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            throw new ResourceOperationException('ai_router.certificate_read_failed', 'The admitted collector certificate is unreadable.', 422);
        }

        return $contents;
    }
}
