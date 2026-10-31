<?php

declare(strict_types=1);

namespace App\Actions\Doctor\Intent;

use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceState;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;
use App\Models\Tool;
use Throwable;

/** Stored expectations only. Native Swamp probes resolve accounts and observe hosts. */
final readonly class RuntimeDoctorIntent
{
    public function __construct(
        private DevelopmentHibernationPolicy $hibernation,
        private MetricsFirewallExpectationProvider $firewall,
        private ToolManagerRegistry $managers,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function execute(Node $node): array
    {
        $processes = Process::query()->with('owner')->where(function ($query) use ($node): void {
            $query->where(function ($query) use ($node): void {
                $query->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', Instance::query()->select('id')->where('node_id', $node->id)->where('status', '!=', InstanceState::Removing));
            })->orWhere(function ($query) use ($node): void {
                $query->where('owner_type', Node::class)->where('owner_id', $node->id);
            });
        })->orderBy('id')->get()->map(function (Process $process): array {
            $runtime = $process->runtime->value;

            return ['id' => $process->id, 'name' => $process->name, 'runtime' => $runtime, 'desired' => $process->desired_state->value, 'keep_alive' => $process->keep_alive,
                'hibernation_path' => $this->hibernation->appliesToProcess($process) ? RuntimeHibernation::awakePath(RuntimeHibernation::key($process->owner_id)) : null,
                'input' => ['process_id' => $process->id, 'runtime' => $runtime, 'name' => "orbit-process-{$process->id}-{$process->name}".($runtime === 'systemd' ? '.service' : ''), 'desired' => $process->desired_state->value]];
        })->values()->all();
        $schedules = Schedule::query()->with('target')->where('host_node_id', $node->id)->orderBy('id')->get()->map(function (Schedule $schedule) use ($node): array {
            $target = $schedule->target;
            $instance = $target instanceof Instance ? $target : null;
            $production = $instance?->placedOnAppProd() === true;

            return ['id' => $schedule->id, 'name' => $schedule->name,
                'placement_node_id' => $target instanceof Node ? $target->id : $instance?->node_id,
                'intent_invalid' => ! $target instanceof Node && ! $instance instanceof Instance,
                'user' => $production ? $instance->production_user : $node->user,
                'production_home' => $production ? $instance->production_home : null,
                'working_directory' => $instance === null ? null : ($instance->source_is_laravel === true ? $instance->applicationDirectory() : ($production ? $instance->production_home.'/current' : $instance->checkout_path)),
                'input' => ['id' => $schedule->id, 'command' => $schedule->command, 'calendar' => $schedule->calendar, 'timeout_seconds' => $schedule->timeout_seconds, 'desired' => $schedule->desired_timer_state->value]];
        })->values()->all();
        $tools = Tool::query()->with('manager')->where('node_id', $node->id)->orderBy('id')->get()->map(function (Tool $tool): array {
            $manager = $this->managers->find($tool->manager->name);

            return ['id' => $tool->id, 'manager' => $tool->manager->name, 'manager_node_id' => $tool->manager->node_id, 'package' => $tool->package, 'package_valid' => $manager?->validatePackage($tool->package) === true, 'version_constraint' => $tool->getAttribute('version_constraint')];
        })->values()->all();
        $environments = Instance::query()->where('node_id', $node->id)->whereNotNull('vp_environment')->orderBy('id')->get()->map(function (Instance $instance): array {
            try {
                $record = $instance->getAttribute('vp_environment');
                $environment = VpEnvironment::fromArray($record);

                return ['id' => $instance->id, 'active' => is_array($record) && ($record['status'] ?? null) === 'active', 'vp_version' => $environment->vpVersion, 'input' => $environment->input()];
            } catch (Throwable) {
                return ['id' => $instance->id, 'active' => false];
            }
        })->values()->all();

        return array_map(array_values(...), ['processes' => $processes, 'schedules' => $schedules,
            'schedule_inventory' => [['scan' => $schedules !== [] || $node->roles()->exists(), 'known_ids' => array_column($schedules, 'id')]],
            'tools' => $tools, 'tool_environments' => $environments, 'tool_inventory' => [['scan' => true]],
            'firewall_rules' => FirewallRule::query()->where('node_id', $node->id)->orderBy('id')->get()->map(fn (FirewallRule $rule): array => [...$this->firewallTarget(FirewallInspectionTarget::fromRule($rule)), 'status' => $rule->status->value])->values()->all(),
            'firewall_expectations' => array_map($this->firewallTarget(...), $this->firewall->for($node))]);
    }

    /** @return array<string, mixed> */
    private function firewallTarget(FirewallInspectionTarget $target): array
    {
        $shape = $target->shape;

        return ['id' => $target->resourceId, 'name' => $target->resourceName, 'shape' => new UfwRuleShape($shape->comment, $shape->action, $shape->direction, $shape->source, $shape->destination, $shape->port, $shape->protocol, $shape->inInterface, $shape->outInterface, $shape->family)->toAgentInput()];
    }
}
