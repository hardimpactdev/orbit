<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Routes\NativeRouteCertificateInput;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Admission-only Route snapshots; the owning native children apply their deltas. */
final readonly class AnalyticsEnableInput
{
    public function __construct(private DevelopmentSiteRepository $sites, private NativeRoutePublicationInput $publications) {}

    /** @param array<string,mixed> $tracking
     * @param  Collection<int,DevelopmentSite>  $before
     * @param  array<string,mixed>  $site
     * @return array<string,mixed>
     */
    public function capture(array $tracking, Collection $before, array $site, string $baseUrl): array
    {
        $routes = Route::query()->whereIn('id', ValidatedData::integerList($tracking['publish']))->get();
        $prospective = function (bool $public, bool $final) use ($routes): Collection {
            $rendered = collect();
            foreach ($routes as $stored) {
                $route = clone $stored;
                $route->unsetRelations();
                $route->sites_published = true;
                $route->status = RouteStatus::Activating;
                $route->replacement_step = $public && $route->publication === RoutePublication::Public ? RouteReplacementStep::PublicActivated : null;
                if ($final) {
                    $route->transition_node_id = null;
                    $route->transition_cluster_id = null;
                }
                $rendered->push(...$this->sites->routeSites($route));
            }

            return $rendered;
        };
        $key = static fn (DevelopmentSite $entry): string => $entry->nodeId.':'.$entry->scope;
        $prepared = $before->concat($prospective(false, false))->keyBy($key)->values();
        $activated = $prepared->concat($prospective(true, false))->keyBy($key)->values();
        $ids = [...ValidatedData::integerList($tracking['publish']), ...ValidatedData::integerList($tracking['remove'])];
        $final = $before->reject(static fn (DevelopmentSite $entry): bool => in_array((int) (preg_match('/\Aroute-([0-9]+)(?:-|\z)/', $entry->scope, $match) ? $match[1] : 0), $ids, true))
            ->concat($prospective(true, true))->keyBy($key)->values();
        $caddy = $dns = $cleanup = $probes = [];
        foreach (ValidatedData::integerList($tracking['caddy_node_ids']) as $id) {
            $node = Node::query()->findOrFail($id);
            [$from, $to] = [$this->publications->snapshot($node, $before), $this->publications->snapshot($node, $final)];
            $content = ValidatedData::object($to['caddy'])['content'] ?? null;
            if (is_string($content) && $content !== (ValidatedData::object($from['caddy'])['content'] ?? null)) {
                $caddy[] = ['node_id' => $id, 'content' => $content];
            }
        }
        foreach (ValidatedData::integerList($tracking['dns_node_ids']) as $id) {
            $node = Node::query()->findOrFail($id);
            $dns[] = ['node_id' => $id, 'before' => $this->publications->snapshot($node, $before)['dns'], 'after' => $this->publications->snapshot($node, $final)['dns']];
        }
        foreach (RouteExecutionProof::objects($tracking['cleanup']) as $leaf) {
            $node = Node::query()->findOrFail(ValidatedData::integer($leaf['node_id']));
            $scope = ValidatedData::string($leaf['scope']);
            if (! $final->where('nodeId', $node->id)->contains(static fn (DevelopmentSite $entry): bool => ($entry->certificateScope ?? $entry->scope) === $scope)) {
                $cleanup[] = ['node_id' => $node->id, 'leaf' => array_intersect_key(app(NativeRouteCertificateInput::class)->scopeInput($node, $scope), array_flip(['user', 'scope']))];
            }
        }
        // From 209 to the Router over its private address, with the tracking host as SNI.
        foreach (RouteExecutionProof::objects($tracking['public_verifications']) as $entry) {
            $probe = ValidatedData::object(ValidatedData::object($entry['verification'])['probe']);
            $probes[] = ['node_id' => ValidatedData::integer($entry['node_id']), 'address' => ValidatedData::string($probe['address']), 'domain' => ValidatedData::string($probe['domain'])];
        }
        $leaves = array_map(static fn (array $leaf): array => Arr::except($leaf, ['route_id', 'in_use']), RouteExecutionProof::objects($tracking['certificates']));

        return ['base_url' => $baseUrl, 'site' => $site, ...app(AnalyticsTrackingSteps::class)->build($caddy, $dns, $cleanup, $leaves, $probes, true)];
    }
}
