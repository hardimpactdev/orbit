<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $assignments = $this->appScheduleAssignments();

        Schema::table('schedules', static function (Blueprint $table): void {
            $table
                ->foreignId('app_instance_id')
                ->nullable()
                ->after('app_id')
                ->constrained('app_instances')
                ->cascadeOnDelete();
            $table->index(['app_instance_id', 'name']);
        });

        DB::transaction(static function () use ($assignments): void {
            foreach ($assignments as $scheduleId => $assignment) {
                DB::table('schedule_runs')
                    ->where('schedule_key', $assignment['previous_schedule_key'])
                    ->update(['schedule_key' => $assignment['schedule_key']]);
                DB::table('schedule_locks')
                    ->where('schedule_key', $assignment['previous_schedule_key'])
                    ->update(['schedule_key' => $assignment['schedule_key']]);
                DB::table('schedules')
                    ->where('id', $scheduleId)
                    ->update([
                        'app_instance_id' => $assignment['app_instance_id'],
                        'schedule_key' => $assignment['schedule_key'],
                        'target_name' => $assignment['target_name'],
                    ]);
            }
        });
    }

    /**
     * @return array<int, array{app_instance_id: int, previous_schedule_key: string, schedule_key: string, target_name: string}>
     */
    private function appScheduleAssignments(): array
    {
        $assignments = [];
        /** @var list<string> $ambiguous */
        $ambiguous = [];

        DB::table('schedules')
            ->where('scope', 'app')
            ->orderBy('id')
            ->get()
            ->each(function (object $schedule) use (&$assignments, &$ambiguous): void {
                $scheduleId = $this->rowInteger($schedule, 'id');
                $appId = $this->rowNullableInteger($schedule, 'app_id');
                $instanceId = $appId === null ? null : $this->provenAppInstanceId($appId);
                $app = $appId === null ? null : DB::table('apps')->where('id', $appId)->first();
                $instance = $instanceId === null
                    ? null
                    : DB::table('app_instances')->where('id', $instanceId)->where('app_id', $appId)->first();

                if ($instanceId === null || ! is_object($app) || ! is_object($instance)) {
                    $ambiguous[] = "schedule_id={$scheduleId}";

                    return;
                }

                $targetName = $this->rowString($app, 'name').'.'.$this->rowString($instance, 'name');
                $scheduleName = $this->rowString($schedule, 'name');
                $assignments[$scheduleId] = [
                    'app_instance_id' => $instanceId,
                    'previous_schedule_key' => $this->rowString($schedule, 'schedule_key'),
                    'schedule_key' => "app:{$targetName}:{$scheduleName}",
                    'target_name' => $targetName,
                ];
            });

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Canonical schedule app-instance ownership requires manual assignment before migration: '
                .implode('; ', $ambiguous)
                .'. Assign every listed app schedule to one concrete app instance, then rerun migrations.',
            );
        }

        return $assignments;
    }

    private function provenAppInstanceId(int $appId): ?int
    {
        $instanceIds = DB::table('app_instances')
            ->where('app_id', $appId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if (count($instanceIds) === 1) {
            return $instanceIds[0];
        }

        return null;
    }

    private function rowInteger(object $row, string $field): int
    {
        $value = $this->rowValue($row, $field);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("Canonical schedule ownership row has an invalid {$field} integer.");
    }

    private function rowNullableInteger(object $row, string $field): ?int
    {
        return $this->rowValue($row, $field) === null ? null : $this->rowInteger($row, $field);
    }

    private function rowString(object $row, string $field): string
    {
        $value = $this->rowValue($row, $field);

        if (! is_string($value)) {
            throw new RuntimeException("Canonical schedule ownership row has an invalid {$field} string.");
        }

        return $value;
    }

    private function rowValue(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }
};
