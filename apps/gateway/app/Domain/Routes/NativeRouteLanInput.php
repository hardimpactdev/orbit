<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Collection;

/** Public Node-wide Route LAN tuples. Caddy sites own placement; Routes share identical allowances. */
final readonly class NativeRouteLanInput
{
    public function migrationPending(int $nodeId): bool
    {
        return app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, $nodeId), 'route.lan_shape') !== 'source-v1';
    }

    /** @param list<int> $admitted
     * @param  list<int>  $observed
     */
    public function recordMigrations(array $admitted, array $observed): void
    {
        if (array_diff($observed, $admitted) !== []) {
            throw new ResourceOperationException('route.lan_result_invalid', 'Migration results require their admitted Nodes.', 409);
        }
        foreach (array_unique($observed) as $nodeId) {
            app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $nodeId), 'route.lan_shape', 'source-v1');
        }
    }

    /** @param Collection<int,DevelopmentSite> $sites
     * @return list<array{port:int,protocol:string,source:string}>
     */
    public function forSites(Node $node, Collection $sites): array
    {
        if (! is_string($node->lan_ip) || $node->lan_ip === '') {
            return [];
        }
        $domains = $sites->where('nodeId', $node->id)->pluck('domain')->all();
        $tuples = [];
        foreach ($sites as $site) {
            if ($site->nodeId === $node->id || ! in_array($site->domain, $domains, true)
                || ! in_array($node->lan_ip, $site->upstreamAddresses, true)) {
                continue;
            }
            $router = Node::query()->findOrFail($site->nodeId);
            if (! is_string($router->lan_ip) || filter_var($router->lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('route.lan_unreachable', 'The configured workload LAN requires a Router LAN address.', 409);
            }
            $tuples[$router->lan_ip] = ['port' => 443, 'protocol' => 'tcp', 'source' => $router->lan_ip];
        }
        ksort($tuples);

        return array_values($tuples);
    }

    /** @param list<array{port:int,protocol:string,source:string}> $before
     * @param  list<array{port:int,protocol:string,source:string}>  $desired
     * @return list<array{port:int,protocol:string,source:string,ensure:string,model_name:string}>
     */
    public function changes(array $before, array $desired): array
    {
        $rules = array_map(static fn (array $rule): array => [...$rule, 'ensure' => 'present'], $desired);
        foreach ($before as $rule) {
            if (! in_array($rule, $desired, true)) {
                $rules[] = [...$rule, 'ensure' => 'absent'];
            }
        }

        return array_map(static fn (array $rule): array => [...$rule,
            'model_name' => substr(hash('sha256', $rule['port'].'/'.$rule['protocol'].'/'.$rule['source']), 0, 16)], $rules);
    }

    /** The caller has already recorded this Route's withdrawal; remaining sites retain shared tuples.
     * @return list<array{port:int,protocol:string,source:string,ensure:string,model_name:string}>
     */
    public function withdrawal(Node $node, Route $route): array
    {
        $repository = app(DevelopmentSiteRepository::class);
        $after = $repository->all();
        $before = $after->concat($repository->routeSites($route))->values();

        return $this->changes($this->forSites($node, $before), $this->forSites($node, $after));
    }
}
