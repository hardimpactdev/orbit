<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->timestamp('pushed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->dropColumn('pushed_at');
        });
    }
};
