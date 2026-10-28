<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->timestamp('stop_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropColumn('stop_confirmed_at');
        });
    }
};
