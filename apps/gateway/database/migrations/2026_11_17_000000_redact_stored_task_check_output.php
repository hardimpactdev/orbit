<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckOutputBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $output = app(TaskCheckOutputBuilder::class);
        DB::table('task_checks')->orderBy('id')->eachById(function (object $check) use ($output): void {
            $updates = ['output' => $check->output === null ? null : $output->tail($check->output)];
            if ($check->deliverable_evidence !== null) {
                $evidence = json_decode($check->deliverable_evidence, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($evidence)) {
                    throw new UnexpectedValueException('Invalid stored task check evidence.');
                }
                $commands = $evidence['commands'] ?? [];
                if (! is_array($commands)) {
                    throw new UnexpectedValueException('Invalid stored task command evidence.');
                }
                foreach ($commands as $id => $command) {
                    if (! is_array($command)) {
                        throw new UnexpectedValueException('Invalid stored task command evidence.');
                    }
                    foreach (['output', 'base_output'] as $key) {
                        if (isset($command[$key]) && is_string($command[$key])) {
                            $command[$key] = $output->tail($command[$key]);
                        }
                    }
                    $commands[$id] = $command;
                }
                if (isset($evidence['commands'])) {
                    $evidence['commands'] = $commands;
                }
                $updates['deliverable_evidence'] = json_encode($evidence, JSON_THROW_ON_ERROR);
            }
            DB::table('task_checks')->where('id', $check->id)->update($updates);
        });
    }

    public function down(): void
    {
        // Removed secrets and discarded output cannot be restored.
    }
};
