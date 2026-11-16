<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Node whose certificate a CI Node's seed listener trusts, as its last passed ci-runner converge opened it. A seed copy targets only a
 * Node whose record names the proof default's Node (docs/reference/node-provisioning.md#the-seed-listener). Null until such a converge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->unsignedBigInteger('seed_sender_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('seed_sender_id');
        });
    }
};
