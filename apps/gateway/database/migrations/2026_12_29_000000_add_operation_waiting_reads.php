<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedInteger('wait_delay_seconds')->nullable();
            $table->timestamp('next_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropColumn(['wait_delay_seconds', 'next_read_at']);
        });
    }
};
