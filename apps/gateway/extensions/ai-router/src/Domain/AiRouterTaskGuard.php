<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The model router's task VM guard ([Credentials in task VMs](/reference/tasks#credentials-in-task-vms)): its own Caddy on the router's
 * Node, on the WireGuard address at port 8318. It passes a request on only when it comes from the task VM WireGuard range, is a POST to
 * exactly one of the inference routes Pi uses, with no query, and carries the task key; it then swaps in the anchor key. Every other
 * request gets 403. The unit loads both keys as systemd credentials, so its Caddyfile holds no secret and no other user reads them; UFW
 * admits port 8318 from the task VM range only.
 */
final readonly class AiRouterTaskGuard
{
    public const int Port = 8318;

    public const string Directory = '/etc/orbit/ai-router-guard';

    public const string Unit = 'orbit-ai-router-guard.service';

    /** Where systemd hands the unit the two key files it loads with `LoadCredential`; only the unit's dynamic user reads them. */
    public const string Credentials = '/run/credentials/'.self::Unit;

    public const string AllowComment = 'orbit:ai-router-task-guard';

    public const string DenyComment = 'orbit:ai-router-task-guard-deny';

    /** The inference routes Pi calls. The management API and every other route of the router get 403. */
    public const array Paths = ['/v1/responses', '/v1/chat/completions', '/v1/messages'];

    public function __construct(private AiRouterState $state, private TaskVmSettings $settings) {}

    /** The router's Node once the extension was set up, or null. */
    public function node(): ?Node
    {
        $id = $this->state->nodeId();

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
        $paths = implode(', ', array_map(static fn (string $path): string => '"'.$path.'"', self::Paths));
        $credentials = self::Credentials;
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
            		# The path as sent, not cleaned: `/v1//responses` or `/x/../v1/responses` do not pass.
            		expression {http.request.orig_uri.path} in [{$paths}] && {http.request.orig_uri.query} == ""
            		header Authorization "Bearer {file.{$credentials}/task.key}"
            	}
            	handle @task {
            		reverse_proxy {$upstream} {
            			header_up Authorization "Bearer {file.{$credentials}/anchor.key}"
            			header_up -X-Api-Key
            			header_up -X-Goog-Api-Key
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

    /** Whether the last succeeded guard run on the Node applied the guard, rather than removed it. */
    public function applied(Node $node): bool
    {
        $last = Operation::query()->where('kind', 'ai_router.task_guard')->where('node_id', $node->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first();

        return $last !== null && (ValidatedData::object($last->input['intent'])['state'] ?? null) === 'present';
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
