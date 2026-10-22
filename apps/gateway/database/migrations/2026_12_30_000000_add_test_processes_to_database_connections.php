<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->unsignedTinyInteger('test_processes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->dropColumn('test_processes');
        });
    }
};
