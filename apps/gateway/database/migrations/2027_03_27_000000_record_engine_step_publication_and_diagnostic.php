<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_engine_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('first_order_expires_ms')->nullable();
            $table->text('error_detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operation_engine_steps', function (Blueprint $table): void {
            $table->dropColumn(['first_order_expires_ms', 'error_detail']);
        });
    }
};
