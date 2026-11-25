<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_idempotency_aliases', function (Blueprint $table): void {
            $table->string('kind');
            $table->string('key', 128);
            $table->string('fingerprint', 128);
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->primary(['kind', 'key']);
            $table->index('operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_idempotency_aliases');
    }
};
