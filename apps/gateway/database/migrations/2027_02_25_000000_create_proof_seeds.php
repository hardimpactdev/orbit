<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seed of the proof default on each Node that offers proof places (docs/reference/development-containers.md#the-seed-on-each-ci-node):
 * a seed is the commit and the time that the default took it, because a retake at the same commit keeps the seed's name. The runtime
 * records the time beside the commit; each Node records its holder, the holder's commit and that time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->timestamp('seed_taken_at')->nullable();
        });
        Schema::create('proof_seeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('holder');
            $table->string('commit');
            $table->timestamp('taken_at');
            $table->unsignedBigInteger('operation_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_seeds');
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn('seed_taken_at');
        });
    }
};
