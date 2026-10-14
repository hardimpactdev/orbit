<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_cache_snapshots', function (Blueprint $table): void {
            $table->foreignId('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('commit')->nullable();
            $table->boolean('fill_pending')->default(false);
            $table->json('blobs');
            $table->json('artifacts');
            $table->timestamps();
        });
        Schema::create('package_cache_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('manager');
            $table->string('package');
            $table->string('version');
            $table->string('integrity');
            $table->string('key');
            $table->timestamps();
            $table->unique(['project_id', 'host_node_id', 'package', 'version', 'key'], 'package_cache_entries_identity_unique');
            $table->index(['package', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_cache_entries');
        Schema::dropIfExists('package_cache_snapshots');
    }
};
