<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Actions\Firewall\ListLiveFirewallRulesAction;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Models\Node;
use App\Models\Route;

final readonly class PublicProcessRouteFirewall
{
    public function __construct(
        private ListLiveFirewallRulesAction $rules,
    ) {}

    public function matches(Route $route, Node $ingress): bool
    {
        $process = $route->customProxy?->process;
        $owner = $process?->owner;
        if ($process === null || ! $owner instanceof Node) {
            return false;
        }
        $upstream = new CustomProxyProcessListener()->resolvePublic($process, $ingress);
        $observed = $this->rules->execute($owner);
        if ($observed['backend_status'] !== 'active') {
            return false;
        }

        return array_any($observed['live'], static function (array $rule) use ($upstream, $owner, $ingress): bool {
            if ($rule['match'] !== 'exact') {
                return false;
            }
            if ($upstream->host === $owner->wireguard_ip) {
                return $rule['comment'] === 'orbit:wireguard-members';
            }

            return $rule['action'] === 'allow' && $rule['source'] === $ingress->lan_ip
                && in_array($rule['destination'], ['any', $upstream->host], true)
                && $rule['port'] === (string) $upstream->port && $rule['protocol'] === 'tcp'
                && $rule['interface'] === null;
        });
    }
}
