<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commit of a default Instance's newest seed snapshot (docs/reference/development-containers.md#records): its create and each
 * development deployment that passes record it, and clones copy that snapshot. Null until the first seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->string('seed_commit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn('seed_commit');
        });
    }
};
