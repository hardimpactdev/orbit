<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Broadcasting\RecordEventType;
use App\Infrastructure\Executor\ExecutorWakeLock;
use App\Jobs\Operations\BroadcastOperationNotice;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final readonly class ExecutorWakeNotices
{
    private const string Table = 'executor_wake_notices';

    public function __construct(private ExecutorWakeLock $lock) {}

    /** @param array<string, mixed> $notice */
    public function record(Operation $operation, RecordEventType $type, array $notice, string $channel): void
    {
        DB::table(self::Table)->insert(['operation_id' => $operation->id, 'type' => $type->value, 'channel' => $channel,
            'notice' => json_encode($notice, JSON_THROW_ON_ERROR), 'reconcile_conn' => $channel === 'orbit' && $operation->status->terminal()
                && is_int($operation->state['conn_launch_owner'] ?? null)]);
    }

    public function pending(): bool
    {
        return DB::table(self::Table)->exists();
    }

    public function relay(?int $operationId = null): void
    {
        $this->lock->run('notices', 0, static function () use ($operationId): void {
            $notices = DB::table(self::Table);
            if ($operationId !== null) {
                $notices->where('operation_id', $operationId);
            }
            foreach ($notices->orderBy('id')->limit(50)->get() as $row) {
                if ($row->reconcile_conn) {
                    ReconcileSwampOperations::dispatch();
                }
                $notice = ValidatedData::object(json_decode((string) $row->notice, true, flags: JSON_THROW_ON_ERROR));
                Bus::dispatch(new BroadcastOperationNotice(RecordEventType::from((string) $row->type), (int) $row->operation_id, $notice, (string) $row->channel));
                DB::table(self::Table)->where('id', $row->id)->delete();
            }
        });
    }
}
