<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

/** Captures only managed residue. The executor never supplies paths, domains or certificate scopes. */
final readonly class OfflineResidueCommand implements OperationGatewayCommand
{
    public function __construct(private DevelopmentSiteRepository $sites, private NodeCaddyfileRenderer $caddy, private DevelopmentDnsConfigRenderer $dns,
        private SharedPhpPoolInput $php, private ManagedUserAccountResolver $accounts) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['removed_node_id', 'action'],
            'properties' => ['removed_node_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'action' => ['enum' => ['prepare', 'prove']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($operation->kind !== 'route.offline_residue_clean' || $input['removed_node_id'] !== ($operation->input['removed_node_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This residue scope was not admitted.', 422);
        }
        $operation->refresh();
        if ($input['action'] === 'prove') {
            RouteExecutionProof::preceding($operation, app(OfflineResidueExecution::class)->context($operation)['descriptors'], 'offline-residue-proof');

            return ['ready' => true, 'removed' => ValidatedData::stringList(data_get($operation->state, 'offline_residue_plan.removed'))];
        }
        if (is_array($operation->state['offline_residue_plan'] ?? null)) {
            return ['prepared' => true];
        }
        $node = Node::query()->with('roles')->findOrFail($operation->node_id);
        $removedNode = $input['removed_node_id'];
        $routeIds = $removedNode === null ? [] : Route::query()->where(static function (Builder $query) use ($removedNode): void {
            $query->where('node_id', $removedNode)->orWhere('generation_basis_node_id', $removedNode)->orWhere('transition_node_id', $removedNode)
                ->orWhereHas('targets.instance', static fn (Builder $q): Builder => $q->where('node_id', $removedNode));
        })->orderBy('id')->pluck('id')->map(StoredInteger::from(...))->all();
        $residues = RouteRemovalResidue::query()->where('node_id', $node->id)->orderBy('id')->get()->filter(
            static fn (RouteRemovalResidue $row): bool => $removedNode === null ? ! Route::query()->whereKey($row->route_id)->exists() : in_array($row->route_id, $routeIds, true));
        $routeIds = array_values(array_unique([...$routeIds, ...$residues->map(static fn (RouteRemovalResidue $row): int => $row->route_id)->all()]));
        $plan = ['rows' => $residues->map(static fn (RouteRemovalResidue $row): array => ['id' => $row->id, 'route_id' => $row->route_id,
            'domain' => $row->domain, 'steps' => $row->steps, 'attempts' => $row->attempts])->values()->all(), 'removed' => [], 'certificates' => [], 'firewall' => []];
        if ($routeIds !== []) {
            $render = $this->caddy->render($node);
            if (! $render->buildable()) {
                return ['error_code' => 'caddy.render_failed'];
            }
            $plan['caddy'] = ['content' => $render->content, 'version' => $render->version, 'listen_addresses' => $render->listenAddresses,
                'unserved' => $render->sites === [] || ! CaddySiteRoles::nodeExpectsCaddy($node->id)];
            $plan['removed'][] = 'caddy-residue';
            if ($residues->contains(static fn (RouteRemovalResidue $row): bool => in_array('php', $row->steps, true))) {
                $plan['php'] = $this->php->forNode($node);
                $plan['removed'][] = 'php-pool-residue';
            }
            $sites = $this->sites->forNode($node);
            $home = $this->accounts->resolve($node)->home;
            foreach ($routeIds as $routeId) {
                $scopes = ["route-{$routeId}", "route-{$routeId}-router", "route-{$routeId}-router-hostname-change", "route-{$routeId}-ingress"];
                foreach ($scopes as $scope) {
                    if (! $sites->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                        $plan['certificates'][] = compact('scope', 'home');
                        $plan['removed'][] = $scope;
                    }
                }
                $domain = Route::query()->whereKey($routeId)->value('domain');
                // A still-used Route keeps its workload firewall rule, including primary Instance sites.
                if (! $sites->contains(static fn (DevelopmentSite $site): bool => $site->domain === $domain || in_array($site->scope, $scopes, true))) {
                    $plan['firewall'][] = ['operation' => 'remove', 'route_id' => $routeId];
                    $plan['removed'][] = "route-{$routeId}-firewall";
                }
            }
        }
        if ($node->roles->contains(static fn ($role): bool => in_array($role->role, [RoleName::Vpn, RoleName::Gateway], true))) {
            $plan['dns'] = ['records' => $this->dns->render(), 'catalog' => json_encode(['requesters' => $this->dns->registeredRequesters(), ...$this->dns->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"];
            $plan['removed'][] = 'private-dns-residue';
        }
        $operation->update(['state' => [...($operation->state ?? []), 'offline_residue_plan' => $plan]]);

        return ['prepared' => true];
    }
}
