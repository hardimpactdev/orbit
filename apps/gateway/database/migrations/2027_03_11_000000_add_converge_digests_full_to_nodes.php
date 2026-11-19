<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a full-scope `node:converge` wrote `nodes.converge_digests`. Only such a record survives a scoped converge; a role addition or
 * role convergence writes digests of other phases (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->boolean('converge_digests_full')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('converge_digests_full');
        });
    }
};
