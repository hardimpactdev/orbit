<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apt_repositories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            $table->json('definition');
            $table->timestamps();
            $table->unique(['node_id', 'name']);
        });
        Schema::table('tools', function (Blueprint $table): void {
            $table->foreignId('apt_repository_id')->nullable()->constrained('apt_repositories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('apt_repository_id');
        });
        Schema::dropIfExists('apt_repositories');
    }
};
