<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->unsignedBigInteger('librarian_started_at_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->dropColumn('librarian_started_at_ms');
        });
    }
};
