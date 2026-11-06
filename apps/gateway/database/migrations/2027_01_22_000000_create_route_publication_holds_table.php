<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row holds one Node for one Route write, from its admission to its terminal state (docs/reference/routes.md#coordinate-publication).
 * The primary key on the Node is the exclusion: a second insert for the Node fails, and that Operation waits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('route_publication_holds')) {
            return;
        }
        Schema::create('route_publication_holds', static function (Blueprint $table): void {
            $table->unsignedBigInteger('node_id')->primary();
            $table->unsignedBigInteger('operation_id')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_publication_holds');
    }
};
