<?php

declare(strict_types=1);

namespace App\Actions\Doctor\Intent;

use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceState;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Process;

/** Runtime liveness expectations only; owning native families verify configuration. */
final readonly class RuntimeDoctorIntent
{
    public function __construct(private DevelopmentHibernationPolicy $hibernation) {}

    /**
     * @return array{processes: list<array{id: int, name: string, runtime: string, desired: string, keep_alive: bool, hibernation_path: ?string,
     *     input: array{process_id: int, runtime: string, name: string, desired: string}}>}
     */
    public function execute(Node $node): array
    {
        $processes = Process::query()->with('owner')->where(function ($query) use ($node): void {
            $query->where(function ($query) use ($node): void {
                // A container Instance's units live in its container, out of the Node agent's sight.
                $query->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', Instance::query()->select('id')->where('node_id', $node->id)->where('status', '!=', InstanceState::Removing)
                    ->whereNotIn('id', InstanceRuntime::query()->select('instance_id')));
            })->orWhere(function ($query) use ($node): void {
                $query->where('owner_type', Node::class)->where('owner_id', $node->id);
            });
        })->orderBy('id')->get()->map(function (Process $process): array {
            $runtime = $process->runtime->value;

            return ['id' => $process->id, 'name' => $process->name, 'runtime' => $runtime, 'desired' => $process->desired_state->value, 'keep_alive' => $process->keep_alive,
                'hibernation_path' => $this->hibernation->appliesToProcess($process) ? RuntimeHibernation::awakePath(RuntimeHibernation::key($process->owner_id)) : null,
                'input' => ['process_id' => $process->id, 'runtime' => $runtime, 'name' => "orbit-process-{$process->id}-{$process->name}".($runtime === 'systemd' ? '.service' : ''), 'desired' => $process->desired_state->value]];
        })->values()->all();

        return ['processes' => array_values($processes)];
    }
}
