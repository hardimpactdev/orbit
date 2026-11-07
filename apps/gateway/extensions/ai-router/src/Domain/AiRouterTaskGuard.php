<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;

/**
 * The model router's task VM guard ([Credentials in task VMs](/reference/tasks#credentials-in-task-vms)): its own Caddy on the router's
 * Node, on the WireGuard address at port 8318. It passes a request on only when it comes from the task VM WireGuard range, calls an
 * inference route Pi uses and carries the task key; it then swaps in the anchor key. Every other request gets 403. The guard reads both
 * keys from files on the Node, so its Caddyfile holds no secret, and UFW admits port 8318 from the task VM range only.
 */
final readonly class AiRouterTaskGuard
{
    public const int Port = 8318;

    public const string Directory = '/etc/orbit/ai-router-guard';

    public const string AllowComment = 'orbit:ai-router-task-guard';

    public const string DenyComment = 'orbit:ai-router-task-guard-deny';

    /** The inference routes Pi calls. The management API and every other route of the router get 403. */
    public const array Paths = ['/v1/responses', '/v1/chat/completions', '/v1/messages'];

    public function __construct(private AiRouterState $state, private TaskVmSettings $settings) {}

    /** The router's Node while the extension is enabled, or null. */
    public function node(): ?Node
    {
        $id = $this->state->enabled() ? $this->state->nodeId() : null;

        return $id === null ? null : Node::query()->find($id);
    }

    /** The origin Pi on a task VM calls. */
    public function origin(Node $node): string
    {
        return 'http://'.$this->address($node).':'.self::Port;
    }

    public function caddyfile(Node $node): string
    {
        $address = $this->address($node);
        $range = $this->settings->wireguardRange;
        $port = self::Port;
        $paths = implode(' ', self::Paths);
        $directory = self::Directory;
        $upstream = '127.0.0.1:'.$this->routerPort();

        return <<<CADDY
            # Managed by Orbit: the ai-router task VM guard (/reference/tasks#credentials-in-task-vms).
            {
            	admin off
            	persist_config off
            	auto_https off
            }

            http://:{$port} {
            	bind {$address}
            	@task {
            		remote_ip {$range}
            		method POST
            		path {$paths}
            		header Authorization "Bearer {file.{$directory}/task.key}"
            	}
            	handle @task {
            		reverse_proxy {$upstream} {
            			header_up Authorization "Bearer {file.{$directory}/anchor.key}"
            		}
            	}
            	handle {
            		respond 403
            	}
            }

            CADDY;
    }

    /**
     * Port 8318 from the task VM range, then a deny for every other WireGuard member. The guard inserts both above the WireGuard
     * member trust, which admits every port.
     *
     * @return array{allow: UfwManagedRule, deny: UfwManagedRule}
     */
    public function rules(Node $node): array
    {
        $address = $this->address($node);
        $range = $this->settings->wireguardRange;
        $port = (string) self::Port;

        return [
            'allow' => new UfwManagedRule(new UfwRuleShape(self::AllowComment, 'allow', 'in', $range, $address, $port, 'tcp', 'orbit', null, 'v4'),
                ['sudo', 'ufw', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $range, 'to', $address, 'port', $port, 'comment', self::AllowComment]),
            'deny' => new UfwManagedRule(new UfwRuleShape(self::DenyComment, 'deny', 'in', 'any', $address, $port, 'tcp', 'orbit', null, 'v4'),
                ['sudo', 'ufw', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', $address, 'port', $port, 'comment', self::DenyComment]),
        ];
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException('ai_router.node_invalid', "Node [{$node->name}] has no valid WireGuard IPv4 address.", 422);
        }

        return $address;
    }

    /** The router's own port, from its recorded URL; the guard reaches it on loopback. */
    private function routerPort(): int
    {
        $port = parse_url((string) $this->state->routerUrl(), PHP_URL_PORT);

        return is_int($port) ? $port : throw new ResourceOperationException('ai_router.disabled', 'The ai-router has no router URL with a port.', 409);
    }
}
