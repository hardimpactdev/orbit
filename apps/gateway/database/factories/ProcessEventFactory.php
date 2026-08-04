<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProcessEventType;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProcessEvent>
 */
class ProcessEventFactory extends Factory
{
    protected $model = ProcessEvent::class;

    public function definition(): array
    {
        return [
            'event' => ProcessEventType::Started,
            'event_id' => (string) Str::uuid(),
            'process_id' => Process::factory(),
            'process_name' => 'vite',
            'app_id' => Project::factory(),
            'workspace_id' => null,
            'node_id' => Node::factory(),
            'unit_name' => 'orbit_docs_main_vite',
            'exit_code' => null,
            'exit_status' => null,
            'exited_at' => null,
            'recorded_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ProcessEvent $event): void {
            if (is_string($event->process_name) && $event->process_name !== '') {
                return;
            }

            $process = $event->process;

            if ($process instanceof Process && is_string($process->name) && $process->name !== '') {
                $event->process_name = $process->name;

                return;
            }

            $event->process_name = 'unknown';
        });
    }
}
