<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subnet of a Node's CI bridge `orbitci0`, which Incus chose when the ci-runner role made it. The ci-runner converge reads it and
 * the Gateway records it, so the Instance bridge allocator never takes a /24 that it holds (docs/reference/development-containers.md#nodes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('ci_bridge')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('ci_bridge');
        });
    }
};
