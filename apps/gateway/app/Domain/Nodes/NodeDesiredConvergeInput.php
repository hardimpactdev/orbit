<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolPackageProtection;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;

/** Immutable, recorded desired state. No live reads and no Instance publication payloads. */
final readonly class NodeDesiredConvergeInput
{
    /** @param list<string> $roles
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function capture(Node $node, array $roles, bool $all, Operation $operation, ?Node $hub): array
    {
        // The agent's configuration refuses an unsupported Node before any execution copy.
        $agent = $this->agent($node);
        $recipe = new RecipeLoader()->source();
        $copies = ['agent_verifier' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'node-converge-operation-'.$operation->id.'-agent-verifier', (string) $node->agent_secret_hash)];
        $plans = [];
        $playbook = null;
        foreach ($node->roles()->whereIn('role', $roles)->orderBy('id')->get() as $assignment) {
            $snapshot = app(NodeRoleDirectInput::class)->capture($node, $assignment, false);
            $plan = app(NativeNodeRoleAddInput::class)->capture($node, $assignment, $snapshot, [], $operation, true);
            $rolePlan = $plan['intent'];
            $runtime = ValidatedData::object($rolePlan['runtime']);
            $gatewayDns = $runtime['gateway_dns'];
            if (is_array($gatewayDns) && (isset($gatewayDns['listener']) || isset($gatewayDns['withdraw']))) {
                $playbook = 'operation-node-converge-local-dns.yml';
            }
            // A converge consumes only the target runtime, never role admission's publication/fleet plays.
            $rolePlan['publications'] = [];
            $rolePlan['exporters'] = [];
            $rolePlan['prometheus'] = null;
            $versions = array_keys($recipe->phpExtensions($recipe->profile($assignment->role->value)));
            $phpPlans = array_map(ValidatedData::object(...), is_array($rolePlan['php'] ?? null) ? $rolePlan['php'] : []);
            $rolePlan['php'] = array_values(array_filter($phpPlans, static fn (array $php): bool => in_array($php['version'], $versions, true)));
            $services = array_map(ValidatedData::object(...), is_array($rolePlan['services'] ?? null) ? $rolePlan['services'] : []);
            $rolePlan['services'] = array_values(array_filter($services, static fn (array $service): bool => preg_match('/^php([0-9.]+)-fpm[.]service$/', ValidatedData::string($service['name']), $match) !== 1 || in_array($match[1], $versions, true)));
            $plans[] = $rolePlan;
            if (array_intersect_key($copies, $plan['execution_secrets']) !== []) {
                throw new \LogicException('Converge role execution-copy names must be distinct.');
            }
            $copies = [...$copies, ...$plan['execution_secrets']];
        }

        return ['intent' => $this->intent($node, $agent, $roles, $all, $hub, $plans, $playbook), 'execution_secrets' => $copies];
    }

    /**
     * The intent of a base converge (`node.converge` with `base: true`), as {@see capture()} builds it, without an Operation:
     * it makes no execution copy and writes nothing. The nested-fleet refresh compares its digest (`orbit:node-converge-digest`).
     *
     * @return array<string,mixed>
     */
    public function baseIntent(Node $node): array
    {
        return $this->intent($node, $this->agent($node), [], false, null, [], null);
    }

    /** The digest of {@see baseIntent()}: it holds no time, token or random id, so it moves only when the base intent does. */
    public function baseDigest(Node $node): string
    {
        return hash('sha256', json_encode($this->baseIntent($node), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function agent(Node $node): array
    {
        $apps = ValidatedData::object($node->settings['apps'] ?? []);
        $root = ValidatedData::nullableString($apps['path'] ?? null) ?? ($node->user === 'root' ? '/root' : '/home/'.$node->user).'/apps';

        return app(NodeAgentSshExecutor::class)->configurationForRoot($node, $root);
    }

    /**
     * @param  array<string,mixed>  $agent
     * @param  list<string>  $roles
     * @param  list<array<string,mixed>>  $plans
     * @return array<string,mixed>
     */
    private function intent(Node $node, array $agent, array $roles, bool $all, ?Node $hub, array $plans, ?string $playbook): array
    {
        $delivery = app(AgentDelivery::class);
        $recipe = new RecipeLoader()->source();
        $tools = [];
        // Tool records have Node ownership, not an assignment. Explicit role-only scopes do not widen to Node Tools.
        if ($all) {
            foreach (Tool::query()->where('node_id', $node->id)->with(['manager', 'aptRepository'])->orderBy('id')->get() as $tool) {
                $manager = ToolManagerName::from($tool->manager->name);
                $tools[] = ['id' => $tool->id, 'manager_id' => $tool->tool_manager_id, 'manager' => $manager->value, 'package' => $tool->package,
                    'repository' => $tool->aptRepository?->definition,
                    'version_constraint' => $tool->version_constraint, 'installed_version' => $tool->installed_version,
                    'protected' => app(ToolPackageProtection::class)->forNode($node, $manager)];
            }
        }
        $network = null;
        if (($all || in_array('vpn', $roles, true) || in_array('router', $roles, true)) && $hub !== null && $node->wireguard_ip !== null) {
            $network = app(NodeNetworkConvergeInput::class)->converge($node);
            $network['hub'] = [...NodeWireGuardHubInput::intent($hub), 'public_key' => $hub->wireguard_public_key];
        }

        return ['node_id' => $node->id, 'user' => $node->user,
            'ssh' => app(SwampSshExecutionInput::class)->forNode($node), 'roles' => $plans, 'selected_roles' => $roles,
            'agent' => [...$agent, 'sha256' => $delivery->pin($node), 'url' => $delivery->artifacts()[AgentDelivery::key($node)]['url'] ?? null],
            'cli' => app(CliConvergePlan::class)->nodeInput($node), 'kernel' => NodeKernelPolicy::intent(), 'updates' => NodeUpdatePolicy::for($node), 'recipe' => $recipe->json,
            'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']->forArchitecture('amd64')),
            'tools' => $tools, 'network' => $network, 'role_units' => NodeRoleUnits::for($node),
            ...($playbook === null ? [] : ['playbook' => $playbook])];
    }
}
