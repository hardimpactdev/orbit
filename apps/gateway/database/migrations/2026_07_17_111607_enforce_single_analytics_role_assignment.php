<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS node_role_analytics_singleton_unique ON node_role(role) WHERE role = 'analytics'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS node_role_analytics_singleton_unique');
    }
};
