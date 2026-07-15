<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('node_bootstraps', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('node_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('initiating_node_id')->constrained('nodes')->restrictOnDelete();
            $table->json('request');
            $table->string('status')->default('pending');
            $table->json('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_bootstraps');
    }
};
