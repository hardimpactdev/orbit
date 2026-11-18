<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedInteger('operation_version')->default(1);
            $table->json('child_versions')->nullable();
            $table->foreignId('parent_operation_id')->nullable()->constrained('operations')->restrictOnDelete();
            $table->index(['kind', 'operation_version', 'status']);
        });
        Schema::create('deprecated_operation_versions', function (Blueprint $table): void {
            $table->string('kind');
            $table->unsignedInteger('version');
            $table->primary(['kind', 'version']);
            $table->timestamp('eligible_at')->nullable();
        });
        Schema::create('operation_version_dependencies', function (Blueprint $table): void {
            $table->string('kind');
            $table->unsignedInteger('version');
            $table->string('child_kind');
            $table->unsignedInteger('child_version');
            $table->boolean('direct')->default(false);
            $table->primary(['kind', 'version', 'child_kind', 'child_version'], 'operation_dependency_key');
        });
        $this->installAdmissionFence();
        $this->installImmutablePins();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER operations_version_reopen BEFORE UPDATE OF status, lock_key ON operations
            WHEN OLD.status IN ('failed','cancelled','interrupted','succeeded')
                AND (NEW.status IN ('queued','running','waiting') OR (OLD.lock_key IS NULL AND NEW.lock_key IS NOT NULL))
                AND EXISTS (SELECT 1 FROM deprecated_operation_versions AS old_version WHERE old_version.eligible_at IS NOT NULL AND (
                    (old_version.kind = OLD.kind AND old_version.version = OLD.operation_version)
                    OR EXISTS (SELECT 1 FROM operation_version_dependencies AS dep WHERE dep.kind = OLD.kind
                      AND dep.version = OLD.operation_version AND dep.child_kind = old_version.kind AND dep.child_version = old_version.version)
                ))
            BEGIN SELECT RAISE(ABORT, 'operation.version_retired'); END
            SQL);
    }

    public function installImmutablePins(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER operations_version_immutable BEFORE UPDATE OF kind, operation_version, parent_operation_id, child_versions ON operations
            WHEN NEW.operation_version != OLD.operation_version OR NEW.parent_operation_id IS NOT OLD.parent_operation_id OR NEW.child_versions IS NOT OLD.child_versions
                OR (NEW.kind != OLD.kind AND EXISTS (SELECT 1 FROM deprecated_operation_versions AS v WHERE v.kind IN (OLD.kind,NEW.kind)))
            BEGIN SELECT RAISE(ABORT, 'operation.version_immutable'); END
            SQL);
    }

    public function installAdmissionFence(bool $allowRecordedLaunchRetry = false): void
    {
        $terminalRetry = $allowRecordedLaunchRetry ? <<<'SQL'
                      OR (parent.kind = 'conn.launch' AND parent.status = 'succeeded' AND NEW.kind = 'instance.complete'
                        AND NEW.idempotency_key = 'conn-launch:' || parent.id || ':complete:' || json_extract(parent.state, '$.launch_retry_requests.application.id'))
            SQL : '';
        DB::unprepared(<<<SQL
            CREATE TRIGGER operations_version_admission BEFORE INSERT ON operations
            WHEN NEW.operation_version < 1 OR ((EXISTS (
                SELECT 1 FROM deprecated_operation_versions WHERE kind = NEW.kind AND version = NEW.operation_version
            ) OR EXISTS (
                SELECT 1 FROM operation_version_dependencies AS deps JOIN deprecated_operation_versions AS old
                    ON old.kind = deps.child_kind AND old.version = deps.child_version
                WHERE deps.kind = NEW.kind AND deps.version = NEW.operation_version
            ) OR EXISTS (
                SELECT 1 FROM json_each(NEW.child_versions) AS child JOIN deprecated_operation_versions AS old
                    ON old.kind = child.key AND old.version = child.value
            )) AND NOT EXISTS (
                SELECT 1 FROM operations AS parent WHERE parent.id = NEW.parent_operation_id
                    AND (parent.status IN ('queued','running','waiting')
                      {$terminalRetry})
                    AND (
                        EXISTS (SELECT 1 FROM json_each(parent.child_versions) AS child
                            WHERE child.key = NEW.kind AND child.value = NEW.operation_version)
                        OR (parent.child_versions IS NULL AND EXISTS (SELECT 1 FROM operation_version_dependencies AS dep
                            WHERE dep.kind = parent.kind AND dep.version = parent.operation_version AND dep.direct = 1
                                AND dep.child_kind = NEW.kind AND dep.child_version = NEW.operation_version))
                    )
            )) BEGIN SELECT RAISE(ABORT, 'operation.version_deprecated'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS operations_version_admission');
        DB::unprepared('DROP TRIGGER IF EXISTS operations_version_immutable');
        DB::unprepared('DROP TRIGGER IF EXISTS operations_version_reopen');
        Schema::dropIfExists('operation_version_dependencies');
        Schema::dropIfExists('deprecated_operation_versions');
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'operation_version', 'status']);
            $table->dropConstrainedForeignId('parent_operation_id');
            $table->dropColumn(['operation_version', 'child_versions']);
        });
    }
};
