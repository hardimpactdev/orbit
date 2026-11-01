<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Support\ValidatedData;

/** Public certificate intent. Keys and CSRs belong to the native effect Node.
 *
 * @phpstan-type RoleLeaf array{node_id:int,scope:string,domain:string,version:string,root_hash:string}
 * @phpstan-type Withdrawal array{node_id:int,scope:string}
 * @phpstan-type CertificatePlan array{leaves:list<RoleLeaf>,withdrawals:list<Withdrawal>,connections?:list<array{node_id:int,ssh:array<string,mixed>}>}
 */
final readonly class NodeRoleCertificateInput
{
    public function __construct(private LeafCertificateSigner $certificates, private MetricsGatewayResolver $gateways) {}

    /** Capture after the assignment records its desired state. A source denotes relocation.
     * Gateway role convergence does not deploy Gateway web or its certificate.
     *
     * @return CertificatePlan
     */
    public function capture(Node $node, RoleName $role, ?Node $source = null, bool $removing = false): array
    {
        $desired = [];
        $withdrawals = [];
        if (in_array($role, [RoleName::WebSocket, RoleName::Analytics], true)) {
            $scope = $role === RoleName::WebSocket ? 'role-websocket' : 'role-analytics';
            $domain = $role === RoleName::WebSocket ? 'reverb.orbit' : AnalyticsHostname::Value;
            if (! $removing) {
                $desired[] = ['node_id' => $node->id, 'scope' => $scope, 'domain' => $domain];
            }
            if ($removing || $source !== null) {
                $withdrawals[] = ['node_id' => ($source ?? $node)->id, 'scope' => $scope];
            }
        } elseif ($role === RoleName::Metrics) {
            // Moving Metrics keeps the same Gateway site and certificate alive.
            $gateway = $this->gateways->resolve();
            if (! $removing) {
                $desired[] = ['node_id' => $gateway->id, 'scope' => 'role-metrics', 'domain' => 'metrics.orbit'];
            } elseif (CaddySiteRoles::serving(RoleName::Metrics) === []) {
                $withdrawals[] = ['node_id' => $gateway->id, 'scope' => 'role-metrics'];
            }
        } elseif ($role === RoleName::Gateway && CaddySiteRoles::serving(RoleName::Metrics) !== []) {
            if (! $removing) {
                $desired[] = ['node_id' => $node->id, 'scope' => 'role-metrics', 'domain' => 'metrics.orbit'];
            }
            if ($removing || $source !== null) {
                $withdrawals[] = ['node_id' => ($source ?? $node)->id, 'scope' => 'role-metrics'];
            }
        }

        $leaves = [];
        if ($desired !== []) {
            $rootHash = hash('sha256', $this->certificates->rootCertificate());
            foreach ($desired as $leaf) {
                $leaves[] = [...$leaf, 'version' => bin2hex(random_bytes(8)),
                    'root_hash' => $rootHash];
            }
        }

        return ['leaves' => $leaves, 'withdrawals' => $withdrawals];
    }

    /** Capture the pinned transport only for executable role publications, not prospective rendering.
     * @return CertificatePlan
     */
    public function captureForExecution(Node $node, RoleName $role, ?Node $source = null): array
    {
        $plan = $this->capture($node, $role, $source);
        if ($plan['leaves'] !== []) {
            $plan['connections'] = [];
            foreach (array_unique(array_column($plan['leaves'], 'node_id')) as $nodeId) {
                $target = $nodeId === $node->id ? $node : Node::query()->findOrFail($nodeId);
                $plan['connections'][] = ['node_id' => $nodeId, 'ssh' => app(SwampSshExecutionInput::class)->forNode($target)];
            }
        }

        return $plan;
    }

    /** Effect Nodes only; the canonical signer has its own fixed native placement.
     * @param  array<string,mixed>  $snapshot
     * @return list<int>
     */
    public static function participants(array $snapshot): array
    {
        $ids = [];
        foreach (['leaves', 'withdrawals'] as $key) {
            $entries = $snapshot[$key];
            if (! is_array($entries)) {
                throw new \InvalidArgumentException('Role certificate entries must be a list.');
            }
            foreach ($entries as $entry) {
                $ids[] = ValidatedData::integer(ValidatedData::object($entry)['node_id']);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
