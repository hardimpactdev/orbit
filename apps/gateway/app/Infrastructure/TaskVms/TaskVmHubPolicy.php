<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

/** One public policy for the hub's task VM filter and managed ingress rules. */
final readonly class TaskVmHubPolicy
{
    public const string Comment = 'orbit:task-vm-control-ssh';

    public const string Marker = '# Managed by Orbit (task VMs). Filters only the reserved range ';

    public const string Unit = "[Unit]\nDescription=Orbit task VM filter on the WireGuard hub\nDefaultDependencies=no\nAfter=local-fs.target nftables.service\nBefore=wg-quick@orbit.service\n\n[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/sbin/nft -f /etc/orbit/task-vms/hub.nft\n\n[Install]\nWantedBy=wg-quick@orbit.service multi-user.target\n";

    public function controlRule(): ?UfwManagedRule
    {
        if (config('task_vms.dev_cluster_id') === null) {
            return null;
        }
        $control = Node::query()->find(app(SwampSshExecutionInput::class)->identity()['control_node_id']);
        if ($control === null || $control->status !== LifecycleStatus::Active || ! is_string($control->wireguard_ip)) {
            return null;
        }
        $range = TaskVmConfig::wireguardRange(config('task_vms.wireguard_range'));
        if (filter_var($control->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $range->contains($control->wireguard_ip)) {
            throw new TaskVmException('task_vm.invalid_config', 'The SSH control Node requires an IPv4 address outside the task VM range.');
        }
        $source = $control->wireguard_ip;
        $destination = $range->value();

        return new UfwManagedRule(new UfwRuleShape(self::Comment, 'allow', 'fwd', $source, $destination, '22', 'tcp', 'orbit', 'orbit', 'v4'),
            ['sudo', 'ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', $destination, 'port', '22', 'comment', self::Comment]);
    }

    /** Recorded candidates need web access before the Router selection changes.
     * @return list<string>
     */
    public function routerAddresses(int $clusterId): array
    {
        $nodes = Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles
                ->where('role', RoleName::Router)->where('cluster_id', $clusterId))
            ->orderBy('id')->get();
        $addresses = [];
        foreach ($nodes as $node) {
            if (! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new TaskVmException('task_vm.fleet_unavailable', 'Every Cluster Router requires a recorded IPv4 WireGuard address.');
            }
            $addresses[] = $node->wireguard_ip;
        }

        return array_values(array_unique($addresses));
    }

    /** @param list<string> $routers
     * @return list<UfwManagedRule>
     */
    public function routerRules(array $routers, string $range): array
    {
        $destination = TaskVmConfig::wireguardRange($range)->value();
        $rules = [];
        foreach ($routers as $source) {
            foreach (['80', '443'] as $port) {
                $comment = 'orbit:task-vm-router-web-'.str_replace('.', '-', $source).'-'.$port;
                $rules[] = new UfwManagedRule(new UfwRuleShape($comment, 'allow', 'fwd', $source, $destination, $port, 'tcp', 'orbit', 'orbit', 'v4'),
                    ['sudo', 'ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', $destination, 'port', $port, 'comment', $comment]);
            }
        }

        return $rules;
    }

    /** @return list<UfwManagedRule> */
    public function managedRules(): array
    {
        $clusterId = TaskVmSettings::devClusterId();
        if ($clusterId === null) {
            return [];
        }

        $range = TaskVmConfig::wireguardRange(config('task_vms.wireguard_range'))->value();

        return [...array_filter([$this->controlRule()]), ...$this->routerRules($this->routerAddresses($clusterId), $range)];
    }

    /** Render the admitted endpoints using the same rules as the managed catalog.
     * @param  array{range:string,dns:string,gateway:string,pi_port:int,reverb:string,model:string,routers:list<string>}  $input
     */
    public function render(array $input, UfwManagedRule $control): string
    {
        $network = TaskVmConfig::wireguardRange($input['range']);
        if ($network->prefixLength() < 16 || $network->prefixLength() > 30) {
            throw new TaskVmException('task_vm.invalid_config', 'The hub filter requires a reserved IPv4 network between /16 and /30.');
        }
        $range = $network->value();
        $addresses = [$input['dns'], $input['gateway'], ...$input['routers']];
        if ($input['routers'] === [] || array_any($addresses, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            throw new TaskVmException('task_vm.invalid_config', 'The hub endpoints must be IPv4 addresses.');
        }
        foreach (['reverb', 'model'] as $endpoint) {
            if (preg_match('/\A([0-9.]+):([0-9]+)\z/D', $input[$endpoint], $match) !== 1 || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $match[2] < 1 || (int) $match[2] > 65535) {
                throw new TaskVmException('task_vm.invalid_config', 'The hub service endpoints must be IPv4 sockets.');
            }
        }
        if ($input['pi_port'] < 1 || $input['pi_port'] > 65535) {
            throw new TaskVmException('task_vm.invalid_config', 'The Pi port is invalid.');
        }
        [$reverb, $reverbPort] = explode(':', $input['reverb']);
        [$model, $modelPort] = explode(':', $input['model']);
        $dns = $input['dns'];
        $gateway = $input['gateway'];
        $pi = $input['pi_port'];
        $routerRules = implode("\n        ", array_map(static fn (UfwManagedRule $rule): string => 'ip saddr '.$rule->shape->source.' ip daddr '.$range.' tcp dport '.$rule->shape->port.' accept comment "'.$rule->shape->comment.'"',
            $this->routerRules($input['routers'], $range)));
        $source = $control->shape->source;
        $comment = self::Comment;
        $marker = self::Marker;

        return <<<NFT
        {$marker}{$range}.
        table inet orbit_task_vms
        delete table inet orbit_task_vms

        table inet orbit_task_vms {
            chain forward {
                type filter hook forward priority -5; policy accept;
                meta nfproto != ipv4 accept
                ip saddr != {$range} ip daddr != {$range} accept
                ct state established,related accept
                ip saddr {$range} ip daddr {$gateway} tcp dport 443 accept
                ip saddr {$range} ip daddr {$reverb} tcp dport {$reverbPort} accept
                ip saddr {$range} ip daddr {$model} tcp dport {$modelPort} accept
                ip saddr {$gateway} ip daddr {$range} tcp dport { 22, {$pi} } accept
                ip saddr {$source} ip daddr {$range} tcp dport 22 accept comment "{$comment}"
                {$routerRules}
                ip saddr {$range} drop
                ip daddr {$range} drop
            }

            chain input {
                type filter hook input priority -5; policy accept;
                meta nfproto != ipv4 accept
                ip saddr != {$range} accept
                ct state established,related accept
                ip daddr {$gateway} tcp dport 443 accept
                ip daddr {$reverb} tcp dport {$reverbPort} accept
                ip daddr {$model} tcp dport {$modelPort} accept
                ip daddr {$dns} udp dport 53 accept
                ip daddr {$dns} tcp dport 53 accept
                drop
            }
        }

        NFT;
    }
}
