<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Cluster change that publishes its private DNS before it commits stores the pending TLD, state or Router here first,
 * so every render, including a parallel one, reads the same stored state. Each source of change keeps its own hold
 * (`{source: {at, values}}`) and clears only that one when it ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clusters', static function (Blueprint $table): void {
            $table->json('dns_pending')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clusters', static function (Blueprint $table): void {
            $table->dropColumn('dns_pending');
        });
    }
};
