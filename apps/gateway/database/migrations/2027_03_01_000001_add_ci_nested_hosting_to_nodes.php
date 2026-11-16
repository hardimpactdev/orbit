<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a CI-only Node's last passed ci-runner add or role converge prepared nested hosting. Such a Node hosts nested fleets without
 * the nested-fleet switch (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node). False until such a converge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->boolean('ci_nested_hosting')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('ci_nested_hosting');
        });
    }
};
