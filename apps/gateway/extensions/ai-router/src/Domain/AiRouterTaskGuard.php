<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The model router's task VM guard ([Credentials in task VMs](/reference/tasks#credentials-in-task-vms)): its own Caddy on the router's
 * Node, on the WireGuard address at port 8318. It passes a request on only when it comes from the task VM WireGuard range or a
 * [forwarder Node](/reference/ai-router#container-model-forwarder), is a POST to exactly one of the inference routes, with no query or
 * Claude Code's `beta=true` on the messages routes, and carries the task key; it then swaps in the anchor key. Any other request from
 * those sources gets 401, and every request from elsewhere 403. The unit loads both keys as systemd credentials, so its Caddyfile holds no secret and no other user reads them; UFW admits port
 * 8318 from those sources only.
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

    /** The inference routes Pi, Claude Code and Codex call. The management API and every other route of the router get 403. */
    public const array Paths = ['/v1/responses', '/v1/chat/completions', '/v1/messages', '/v1/messages/count_tokens'];

    /** The routes where Claude Code's fixed `?beta=true` passes; it sends it on every call and has no setting to drop it. */
    public const array BetaPaths = ['/v1/messages', '/v1/messages/count_tokens'];

    /** Guard and forwarder runs share it: two at once could each drop the other's forwarder Node from the guard. */
    public const string LockPrefix = 'ai_router_guard:';

    public function __construct(private AiRouterState $state, private TaskVmSettings $settings, private AiRouterModelForwarder $forwarder,
        private AiRouterSecrets $secrets, private SwampSshExecutionInput $ssh) {}

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

    /**
     * @param  list<Node>  $forwarders  The Nodes whose containers reach the guard through a forwarder.
     */
    public function caddyfile(Node $node, array $forwarders): string
    {
        $address = $this->address($node);
        $sources = implode(' ', $this->sources($forwarders));
        $port = self::Port;
        $list = static fn (array $paths): string => implode(', ', array_map(static fn (string $path): string => '"'.$path.'"', $paths));
        $paths = $list(self::Paths);
        $betaPaths = $list(self::BetaPaths);
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
            		remote_ip {$sources}
            		method POST
            		# The path and query as sent, not cleaned: `/v1//responses`, `/x/../v1/responses` or `?beta=true&key=x` do not pass.
            		expression {http.request.orig_uri.path} in [{$paths}] && ({http.request.orig_uri.query} == "" || ({http.request.orig_uri.query} == "beta=true" && {http.request.orig_uri.path} in [{$betaPaths}]))
            		header Authorization "Bearer {file.{$credentials}/task.key}"
            	}
            	handle @task {
            		reverse_proxy {$upstream} {
            			header_up Authorization "Bearer {file.{$credentials}/anchor.key}"
            			header_up -X-Api-Key
            			header_up -X-Goog-Api-Key
            		}
            	}
            	# An admitted source with a refused request: a forwarder run's end check tells this from a source the guard refuses.
            	@source remote_ip {$sources}
            	handle @source {
            		respond 401
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

    /** Port 8318 from one forwarder Node's WireGuard address, above the deny. */
    public function forwarderRule(Node $node, Node $forwarder): UfwManagedRule
    {
        $address = $this->address($node);
        $source = $this->address($forwarder);
        $port = (string) self::Port;
        $comment = self::AllowComment.'-node-'.$forwarder->id;

        return new UfwManagedRule(new UfwRuleShape($comment, 'allow', 'in', $source, $address, $port, 'tcp', 'orbit', null, 'v4'),
            ['sudo', 'ufw', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', $address, 'port', $port, 'comment', $comment]);
    }

    /**
     * The Nodes whose last succeeded forwarder run left a forwarder in place.
     *
     * @return list<Node>
     */
    public function forwarders(Node $node): array
    {
        return array_column($this->forwarder->present($node), 'node');
    }

    /**
     * The intent of the guard phase (docs/cli/ai-router/task-guard.mmd): its Caddyfile and its UFW rules. With `$remove`, the phase takes
     * the guard off the Node. While it keeps the guard, `tasks/firewall.yml` removes a forwarder Node's allow that the rules no longer name.
     *
     * @param  list<Node>  $forwarders
     * @return array<string, mixed>
     */
    public function intent(Node $node, array $forwarders, bool $remove): array
    {
        $rules = $this->rules($node);
        $allows = array_map(fn (Node $forwarder): UfwManagedRule => $this->forwarderRule($node, $forwarder), $forwarders);
        $ufw = static fn (array $arguments): array => ['ufw', ...array_slice($arguments, 2)];
        $insert = static fn (UfwManagedRule $rule): array => ['ufw', 'insert', '1', ...array_slice($rule->arguments, 2)];

        return [
            'ssh' => $this->ssh->forNode($node),
            'state' => $remove ? 'absent' : 'present',
            'origin' => $this->origin($node),
            'directory' => self::Directory,
            'unit' => self::Unit,
            'caddyfile' => $this->caddyfile($node, $forwarders),
            // The shape `tasks/firewall.yml` keeps: the WireGuard member trust first, then each deny inserted at the top and its allow
            // after it, so the allow ends above the deny and both above the trust; it re-places a pair it finds out of order. Each
            // forwarder Node's allow follows, inserted at the top too, and moves with its pair.
            'firewall' => [
                // The catalog reads the contributed rules, this guard's among them, so it resolves when used.
                'desired' => [$ufw(app(NodeFirewallRuleCatalog::class)->forNode($node)[1]->arguments),
                    ...($remove ? [] : [$insert($rules['deny']), $insert($rules['allow']), ...array_map($insert, $allows)])],
                'remove' => $remove ? array_map(static fn (UfwManagedRule $rule): array => $rule->removalArguments(),
                    [$rules['allow'], $rules['deny'], ...$allows]) : [],
            ],
        ];
    }

    /**
     * Vault copies of the task key and the anchor key, which the guard phase writes on the Node; the intent holds neither.
     *
     * @return array{task_key: array{vault: string, key: string}, anchor_key: array{vault: string, key: string}}
     */
    public function executionSecrets(Node $node): array
    {
        $vault = 'orbit-control-inputs';
        $shared = app(SwampCommandStep::class);

        return [
            'task_key' => $shared->executionCopy($vault, 'ai-router-task-key-node-'.$node->id, $this->secrets->task()),
            'anchor_key' => $shared->executionCopy($vault, 'ai-router-anchor-key-node-'.$node->id,
                $this->secrets->anchor(rtrim((string) $this->state->routerUrl(), '/'))),
        ];
    }

    public function lock(Node $node): string
    {
        return self::LockPrefix.$node->id;
    }

    /** Whether the last succeeded guard or forwarder run on the Node applied the guard, rather than removed it. A forwarder run always applies it. */
    public function applied(Node $node): bool
    {
        $last = Operation::query()->whereIn('kind', ['ai_router.task_guard', AiRouterModelForwarder::Kind])->where('node_id', $node->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first();

        return $last !== null && (ValidatedData::object($last->input['intent'])['state'] ?? null) === 'present';
    }

    /**
     * The task VM range, then each forwarder Node's WireGuard address.
     *
     * @param  list<Node>  $forwarders
     * @return non-empty-list<string>
     */
    private function sources(array $forwarders): array
    {
        return [$this->settings->wireguardRange, ...array_map($this->address(...), $forwarders)];
    }

    /** The Node's WireGuard IPv4 address; `ai_router.node_invalid` when it has none. */
    public function address(Node $node): string
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
