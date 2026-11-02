<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The latest fact of each kind that a Node agent observed; a new row replaces it only when its digest changes.
        Schema::create('node_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->char('digest', 64);
            $table->json('data');
            $table->timestamp('observed_at');
            $table->timestamp('received_at');
            $table->unique(['node_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_facts');
    }
};
