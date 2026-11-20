<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The release artifact of a systemd Process (docs/reference/processes-and-schedules.md#upgrade-a-process). Expand only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_id')->unique()->constrained('processes')->cascadeOnDelete();
            $table->string('source');
            $table->string('repository');
            $table->string('asset');
            $table->string('binary');
            $table->string('checksums')->nullable();
            $table->json('version_args')->nullable();
            $table->string('version')->nullable();
            $table->string('previous_version')->nullable();
            $table->string('sha256')->nullable();
            $table->string('latest_version')->nullable();
            $table->timestamp('latest_checked_at')->nullable();
            $table->timestamps();
            $table->index('repository');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_artifacts');
    }
};
