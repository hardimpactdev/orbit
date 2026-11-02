<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The last dependency inspection of a Project's default branch: the next one reads only what changed.
        Schema::create('project_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source_commit', 64);
            $table->json('manifests');
            $table->json('sources');
            $table->json('targets');
            $table->json('blocked');
            $table->dateTime('inspected_at');
            $table->dateTime('registry_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_inspections');
    }
};
