<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Collection;

/** Admission-only publication assembly shared by standalone and composing Route parents.
 * @phpstan-type CapturedRoute array{route_id:int,domain:string,intent:array<string,mixed>,sites:list<DevelopmentSite>,url_writes:list<array{node_id:int,instance_id:int,name:string,input:array<string,mixed>}>}
 */
final readonly class NativeRouteMutationInput
{
    public function __construct(private DevelopmentSiteRepository $sites, private SwampCommandStep $steps) {}

    /**
     * @param  list<CapturedRoute>  $previous
     * @param  list<CapturedRoute>  $desired
     * @param  list<CapturedRoute>  $staged
     * @param  list<CapturedRoute>  $forwardPrior
     * @param  Collection<int,DevelopmentSite>  $beforeSites
     * @param  Collection<int,DevelopmentSite>  $candidateSites
     * @param  list<int>  $extraNodeIds
     * @param  Closure(Closure():array<string,mixed>):array<string,mixed>  $render
     * @return array{route_mutations:array<string,mixed>,participant_node_ids:list<int>,certificates:list<array<string,mixed>>,execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function capture(array $previous, array $desired, array $staged, array $forwardPrior,
        Collection $beforeSites, Collection $candidateSites, array $extraNodeIds, Closure $render, ?NodeEnrollmentCandidate $enrollment = null): array
    {
        $domains = array_values(array_unique([...array_column($previous, 'domain'), ...array_column($desired, 'domain')]));
        $addressNodes = $candidateSites->filter(static fn (DevelopmentSite $site): bool => $beforeSites->first(static fn (DevelopmentSite $before): bool => $before->addressKey() === $site->addressKey()) != $site)->pluck('nodeId')->all();
        $afterSites = $candidateSites->reject(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->values();
        foreach ($desired as $row) {
            $afterSites->push(...$row['sites']);
        }
        $unaffected = $candidateSites->reject(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->values();
        $withdrawnSites = clone $unaffected;
        foreach ($staged as $row) {
            $withdrawnSites->push(...$row['sites']);
        }
        $preparedSites = clone $withdrawnSites;
        foreach ($forwardPrior as $row) {
            $preparedSites->push(...$row['sites']);
        }
        $preparedSites = $preparedSites->unique(static fn (DevelopmentSite $site): string => $site->addressKey())->values();
        $nodeIds = array_values(array_unique(ValidatedData::integerList([...ValidatedData::integerList($extraNodeIds), ...$addressNodes,
            ...$beforeSites->filter(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->pluck('nodeId')->all(),
            ...$afterSites->filter(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->pluck('nodeId')->all(),
            ...PrivateDnsPublicationNodes::query()->pluck('id')->all()])));
        sort($nodeIds);
        $publications = [];
        $participants = $nodeIds;
        foreach ($nodeIds as $index => $nodeId) {
            $node = Node::query()->findOrFail($nodeId);
            $before = app(NativeRoutePublicationInput::class)->snapshot($node, $beforeSites);
            $forward = $render(fn (): array => [
                ...($enrollment?->nodeId === $nodeId
                    ? app(NativeCaddyPublicationInput::class)->connectionForCandidate($node, $enrollment)
                    : app(NativeCaddyPublicationInput::class)->connectionForNode($node)),
                'prepared' => app(NativeRoutePublicationInput::class)->snapshot($node, $preparedSites),
                'withdrawn' => app(NativeRoutePublicationInput::class)->snapshot($node, $withdrawnSites),
                'after' => app(NativeRoutePublicationInput::class)->snapshot($node, $afterSites),
            ]);
            $participants[] = ValidatedData::integer($forward['control_node_id']);
            $serves = in_array($nodeId, $addressNodes, true) || $beforeSites->where('nodeId', $nodeId)->contains(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))
                || $afterSites->where('nodeId', $nodeId)->contains(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true));
            $restoreConnection = $forward['host_keys'] === null ? $forward['connection']
                : app(NativeCaddyPublicationInput::class)->connectionForNode($node)['connection'];
            $publications[] = ['index' => $index, 'node_id' => $nodeId, 'user' => ValidatedData::string(ValidatedData::object($forward['connection'])['nodeUser']), 'serves_routes' => $serves,
                ...$forward, 'before' => $before, 'restore_connection' => $restoreConnection];
        }
        $captureId = bin2hex(random_bytes(8));
        $secrets = [];
        $forward = $this->stage($desired, $publications, 'forward', $captureId, $secrets);
        $restore = $this->stage($previous, $publications, 'restore', $captureId, $secrets);
        $forward['prepare_certificates'] = array_values(array_unique(array_merge(...array_map(static fn (array $row): array => RouteExecutionProof::objects($row['intent']['certificates']), $staged)), SORT_REGULAR));
        $allLeaves = array_values(array_unique([...RouteExecutionProof::objects($forward['prepare_certificates']), ...RouteExecutionProof::objects($forward['certificates']), ...RouteExecutionProof::objects($restore['certificates'])], SORT_REGULAR));
        foreach (['forward', 'restore'] as $side) {
            $cleanup = [];
            foreach ($allLeaves as $leaf) {
                $publication = array_find($publications, static fn (array $node): bool => $node['node_id'] === $leaf['node_id']);
                if ($publication === null) {
                    throw new \LogicException('Every certificate must have its admitted publication participant.');
                }
                $scopes = ValidatedData::stringList(ValidatedData::object($publication[$side === 'forward' ? 'after' : 'before'])['certificate_scopes']);
                if (! in_array($leaf['scope'], $scopes, true)) {
                    $cleanup[] = array_intersect_key($leaf, array_flip(['node_id', 'user', 'scope', 'home', 'agent_sha256'])) + ['in_use' => false];
                }
            }
            if ($side === 'forward') {
                $forward['cleanup'] = $cleanup;
            } else {
                $restore['cleanup'] = $cleanup;
            }
        }
        $lanPolicy = app(NativeRouteLanInput::class);
        $forward['lan'] = $restore['lan'] = $forward['retire_lan'] = $restore['retire_lan'] = [];
        foreach ($nodeIds as $nodeId) {
            $node = Node::query()->findOrFail($nodeId);
            $prior = $lanPolicy->forSites($node, $beforeSites);
            $desired = $enrollment === null ? $lanPolicy->forSites($node, $afterSites)
                : app(RouteRenderContext::class)->renderEnrollment($enrollment, fn (): array => $lanPolicy->forSites($node, $afterSites));
            $union = array_values(array_unique([...$prior, ...$desired], SORT_REGULAR));
            if ($union === []) {
                continue;
            }
            $forward['lan'][] = $restore['lan'][] = ['node_id' => $nodeId, 'rules' => $lanPolicy->changes([], $union), 'migrate' => $lanPolicy->migrationPending($nodeId)];
            $forward['retire_lan'][] = ['node_id' => $nodeId, 'rules' => $lanPolicy->changes($union, $desired), 'migrate' => false];
            $restore['retire_lan'][] = ['node_id' => $nodeId, 'rules' => $lanPolicy->changes($union, $prior), 'migrate' => false];
        }
        $forward['ingress'] = $restore['ingress'] = $forward['retire_ingress'] = $restore['retire_ingress'] = [];
        foreach ($publications as $publication) {
            $nodeId = $publication['node_id'];
            $beforeOpen = $beforeSites->contains(static fn (DevelopmentSite $site): bool => $site->nodeId === $nodeId && $site->publicListener);
            $afterOpen = $afterSites->contains(static fn (DevelopmentSite $site): bool => $site->nodeId === $nodeId && $site->publicListener);
            if ($beforeOpen === $afterOpen) {
                continue;
            }
            $rules = static fn (bool $open): array => ['node_id' => $nodeId, 'rules' => array_map(
                static fn (int $port): array => ['port' => $port, 'ensure' => $open ? 'present' : 'absent'], [80, 443])];
            $forward['ingress'][] = $restore['ingress'][] = $rules(true);
            $forward['retire_ingress'][] = $rules($afterOpen);
            $restore['retire_ingress'][] = $rules($beforeOpen);
        }
        $certificates = $allLeaves;
        if ($certificates !== []) {
            $participants[] = 209;
        }
        $participants = array_values(array_unique($participants));
        sort($participants);

        return ['route_mutations' => ['schema_version' => 1, 'forward' => $forward, 'restore' => $restore],
            'participant_node_ids' => $participants, 'certificates' => $certificates, 'execution_secrets' => $secrets];
    }

    /** @param array<int,Route> $candidates
     * @param  array<int,Instance>  $placements
     * @return CapturedRoute
     */
    public function route(Route $route, array $candidates = [], array $placements = [], bool $environment = true): array
    {
        $intent = $route->isAnalyticsTracking() ? app(AnalyticsRoutePlan::class)->capture($route) : app(NativeRouteConvergenceIntent::class)->forRoute($route, ['mode' => 'placement']);
        if ($route->isAnalyticsTracking()) {
            $intent += ['node_ids' => array_values(array_unique([...ValidatedData::integerList($intent['caddy_node_ids']), ...ValidatedData::integerList($intent['dns_node_ids'])])), 'workload_node_ids' => [], 'router_node_id' => null];
        }
        $writes = [];
        foreach ($environment ? ValidatedData::integerList($intent['workload_node_ids']) : [] as $nodeId) {
            foreach (app(NativeRouteConvergenceEnvironmentMaterial::class)->forRoute($route, $nodeId, $intent, candidates: $candidates, placements: $placements) as $write) {
                $writes[] = ['node_id' => $nodeId, ...$write];
            }
        }

        return ['route_id' => $route->id, 'domain' => $route->domain, 'intent' => $intent, 'sites' => $this->sites->routeSites($route), 'url_writes' => $writes];
    }

    /** @param list<CapturedRoute> $routes
     * @param  list<array<string,mixed>>  $publications
     * @param  array<string,array{vault:string,key:string}>  $secrets
     * @return array<string,mixed>
     */
    private function stage(array $routes, array $publications, string $mode, string $captureId, array &$secrets): array
    {
        $writes = [];
        $certificates = [];
        foreach ($routes as $route) {
            $certificates = [...$certificates, ...RouteExecutionProof::objects($route['intent']['certificates'])];
            foreach ($route['url_writes'] as $write) {
                $field = 'route_material_'.count($secrets);
                $private = array_intersect_key($write['input'], array_flip(['content', 'app_key', 'app_name']));
                $public = array_diff_key($write['input'], $private);
                $secrets[$field] = $this->steps->executionCopy('orbit-control-inputs', 'route-'.$captureId.'-'.$mode.'-'.$field, json_encode($private, JSON_THROW_ON_ERROR));
                $writes[] = ['index' => count($writes), 'route_id' => $route['route_id'], 'node_id' => $write['node_id'],
                    'instance_id' => $write['instance_id'], 'name' => $write['name'], 'input' => $public, 'secret_field' => $field];
            }
        }
        $certificates = array_values(array_unique($certificates, SORT_REGULAR));
        $publications = array_map(static function (array $row) use ($mode): array {
            if ($mode === 'restore') {
                $row['connection'] = $row['restore_connection'];
                $row['user'] = ValidatedData::string(ValidatedData::object($row['restore_connection'])['nodeUser']);
            }
            unset($row['restore_connection']);

            return [...$row, 'direction' => $mode];
        }, $publications);

        return ['routes' => array_map(static fn (array $row): array => ['route_id' => $row['route_id'], 'domain' => $row['domain'], 'node_ids' => $row['intent']['node_ids'],
            'certificate_scopes' => array_map(static fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'scope'])), RouteExecutionProof::objects($row['intent']['certificates']))], $routes),
            'certificates' => $certificates, 'url_writes' => $writes, 'publications' => $publications];
    }
}
