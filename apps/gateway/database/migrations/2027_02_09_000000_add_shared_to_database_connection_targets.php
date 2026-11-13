<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every existing attachment stays the Instance's alone: a clone keeps only an attachment marked shared.
        Schema::table('database_connection_targets', function (Blueprint $table): void {
            $table->boolean('shared')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('database_connection_targets', function (Blueprint $table): void {
            $table->dropColumn('shared');
        });
    }
};
