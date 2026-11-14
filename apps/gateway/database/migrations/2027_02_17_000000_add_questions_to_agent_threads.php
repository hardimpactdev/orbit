<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a thread's last turn asked, in the agent's own words and options, until its next turn (docs/cli/task/copy-turn.mmd). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->json('questions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->dropColumn('questions');
        });
    }
};
