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
        Schema::table('processes', function (Blueprint $table): void {
            $table->string('runtime')->default('docker')->after('crash_notification');
        });

        DB::table('processes')
            ->whereIn('app_id', DB::table('apps')->where('runtime_kind', 'static')->pluck('id'))
            ->update(['runtime' => 'supervisor']);
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->dropColumn('runtime');
        });
    }
};
