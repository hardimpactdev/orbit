<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedules', static function (Blueprint $table): void {
            $table->unsignedInteger('timeout_seconds')->default(900)->after('execution_value');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', static function (Blueprint $table): void {
            $table->dropColumn('timeout_seconds');
        });
    }
};
