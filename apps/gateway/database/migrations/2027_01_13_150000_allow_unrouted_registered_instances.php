<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->activationGuard(true);
    }

    public function down(): void
    {
        $this->activationGuard(false);
    }

    private function activationGuard(bool $allowUnroutedRegistration): void
    {
        $requiresRoute = $allowUnroutedRegistration
            ? 'AND (NEW.registration_request_id IS NULL OR EXISTS (SELECT 1 FROM route_targets WHERE instance_id = NEW.id))'
            : '';
        DB::unprepared(<<<SQL
            DROP TRIGGER instances_active_route_update;
            CREATE TRIGGER instances_active_route_update
            BEFORE UPDATE OF status ON instances
            WHEN NEW.status = 'active'
                AND (SELECT type FROM projects WHERE id = NEW.project_id) = 'laravel-app'
                {$requiresRoute}
                AND (
                    SELECT COUNT(*)
                    FROM route_targets
                    JOIN routes ON routes.id = route_targets.route_id
                    WHERE route_targets.instance_id = NEW.id
                        AND routes.status IN ('active', 'activating') AND routes.web_root IS NULL
                ) <> 1
            BEGIN
                SELECT RAISE(ABORT, 'An active routed Instance requires exactly one authoritative Route.');
            END;
            SQL);
    }
};
