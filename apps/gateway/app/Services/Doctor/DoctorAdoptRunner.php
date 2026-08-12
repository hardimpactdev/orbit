<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DoctorTargetScope;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionAdopter;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Proxy\ProxyRouteAdopter;

final readonly class DoctorAdoptRunner
{
    public function __construct(
        private NodesProbe $nodesProbe,
        private ProxyRouteAdopter $proxyRouteAdopter,
        private FirewallRuleProbe $firewallRuleProbe,
        private DatabaseConnectionAdopter $databaseConnectionAdopter,
        private DoctorAdoptPolicy $policy,
    ) {}

    /**
     * @param  list<string>  $families
     * @return list<array<string, mixed>>
     */
    public function adopt(Node $node, array $families, DoctorTargetScope $scope): array
    {
        return [
            ...$this->adoptNode($node, $families),
            ...$this->adoptProxy($node, $families, $scope),
            ...$this->adoptFirewallRules($node, $families),
            ...$this->adoptDatabaseConnections($node, $families, $scope),
        ];
    }

    /**
     * @param  list<string>  $families
     * @return list<array<string, mixed>>
     */
    private function adoptNode(Node $node, array $families): array
    {
        if (! in_array('node', $families, strict: true)) {
            return [];
        }

        return $this->actions(
            $node,
            $this->nodesProbe->adopt($node, $this->nodesProbe->snapshotForAdopt($node)),
        );
    }

    /**
     * @param  list<string>  $families
     * @return list<array<string, mixed>>
     */
    private function adoptProxy(Node $node, array $families, DoctorTargetScope $scope): array
    {
        if (! in_array('proxy', $families, strict: true)) {
            return [];
        }

        $snapshot = $this->policy->proxySnapshot($node, $scope);

        if ($snapshot === null) {
            return [];
        }

        return $this->actions(
            $node,
            $this->proxyRouteAdopter->adopt($node, $snapshot),
        );
    }

    /**
     * @param  list<string>  $families
     * @return list<array<string, mixed>>
     */
    private function adoptFirewallRules(Node $node, array $families): array
    {
        if (
            ! in_array('firewall_rule', $families, strict: true)
            || ! $this->policy->canAdoptFirewallRules($node)
        ) {
            return [];
        }

        return $this->actions(
            $node,
            $this->firewallRuleProbe->adopt($node, $this->firewallRuleProbe->introspectNode($node)),
        );
    }

    /**
     * @param  list<string>  $families
     * @return list<array<string, mixed>>
     */
    private function adoptDatabaseConnections(Node $node, array $families, DoctorTargetScope $scope): array
    {
        if (! in_array('database_connection', $families, strict: true)) {
            return [];
        }

        return $this->actions($node, $this->databaseConnectionAdopter->adopt($node, $scope));
    }

    /**
     * @param  list<AdoptResult>  $results
     * @return list<array<string, mixed>>
     */
    private function actions(Node $node, array $results): array
    {
        return array_map(
            static fn (AdoptResult $result): array => [
                'family' => $result->family,
                'node' => $node->name,
                'code' => $result->key,
                'key' => $result->key,
                'mode' => 'adopt',
                'status' => $result->action->value,
                'summary' => $result->summary,
                'details' => $result->detail,
            ],
            $results,
        );
    }
}
