<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_launch_intents', function (Blueprint $table): void {
            $table->foreignId('operation_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('request');
            $table->text('dispatch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_launch_intents');
    }
};
