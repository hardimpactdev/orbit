<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->json('agent_ide_config')->nullable()->after('runtime_requirements');
        });
    }

    public function down(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('agent_ide_config');
        });
    }
};
