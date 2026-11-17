<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The warm proof copies that wait for a proof (docs/cli/proof/run.mdx#warm-copies): the task group that owns each copy, its Node and the
 * seed it was made from. A proof that takes one deletes its row; the group is then its run's. A dropped warm copy keeps its row, with
 * `dropped_at`, until its copy is gone, so it holds its place until its destroy ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_warm_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('seed_commit');
            $table->timestamp('seed_taken_at');
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_warm_copies');
    }
};
