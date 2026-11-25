<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_librarian_watermarks', function (Blueprint $table): void {
            $table->foreignId('conn_environment_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('started_at_ms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_librarian_watermarks');
    }
};
