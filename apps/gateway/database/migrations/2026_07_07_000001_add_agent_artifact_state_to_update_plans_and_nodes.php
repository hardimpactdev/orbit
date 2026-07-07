<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operation_update_plans', static function (Blueprint $table): void {
            $table->json('agent_artifacts')->nullable()->after('cli_artifacts');
        });

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->json('installed_agent')->nullable()->after('installed_cli');
        });
    }

    public function down(): void
    {
        Schema::table('operation_update_plans', static function (Blueprint $table): void {
            $table->dropColumn('agent_artifacts');
        });

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn('installed_agent');
        });
    }
};
