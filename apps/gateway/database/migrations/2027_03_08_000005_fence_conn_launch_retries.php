<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared('DROP TRIGGER IF EXISTS operations_version_admission');
            $versioning = require __DIR__.'/2027_03_08_000004_pin_operation_versions.php';
            $versioning->installAdmissionFence(allowRecordedLaunchRetry: true);
            // A Gateway running the earlier ledger schema could admit a v1 launch before its child declarations shipped.
            DB::unprepared('DROP TRIGGER IF EXISTS operations_version_immutable');
            $pins = ['conn.launch_inspect' => 1, 'conn.launch_dispatch' => 1, 'instance.create' => 1, 'instance.wake' => 1,
                'instance.start' => 1, 'instance.complete' => 1, 'instance.sleep' => 1, 'instance.destroy' => 1];
            foreach ($pins as $kind => $version) {
                DB::table('operation_version_dependencies')->insertOrIgnore(['kind' => 'conn.launch', 'version' => 1,
                    'child_kind' => $kind, 'child_version' => $version, 'direct' => true]);
            }
            DB::table('operations')->where('kind', 'conn.launch')->where('operation_version', 1)
                ->where(fn ($query) => $query->whereNull('child_versions')->orWhere('child_versions', '[]'))
                ->update(['child_versions' => json_encode($pins, JSON_THROW_ON_ERROR)]);
            $versioning->installImmutablePins();
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS operations_version_launch_retry BEFORE UPDATE OF state ON operations
                WHEN OLD.kind = 'conn.launch' AND OLD.status = 'succeeded'
                    AND json_extract(NEW.state, '$.launch_retry_requests.application.id') IS NOT NULL
                    AND json_extract(NEW.state, '$.launch_retry_requests.application.id') IS NOT json_extract(OLD.state, '$.launch_retry_requests.application.id')
                    AND EXISTS (SELECT 1 FROM deprecated_operation_versions AS v WHERE v.eligible_at IS NOT NULL AND
                        ((v.kind = OLD.kind AND v.version = OLD.operation_version) OR (v.kind = 'instance.complete' AND EXISTS
                           (SELECT 1 FROM operation_version_dependencies AS d WHERE d.kind = OLD.kind AND d.version = OLD.operation_version
                             AND d.child_kind = v.kind AND d.child_version = v.version))))
                BEGIN SELECT RAISE(ABORT, 'operation.version_retired'); END
                SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::unprepared('DROP TRIGGER IF EXISTS operations_version_launch_retry');
            DB::unprepared('DROP TRIGGER IF EXISTS operations_version_admission');
            (require __DIR__.'/2027_03_08_000004_pin_operation_versions.php')->installAdmissionFence();
        });
    }
};
