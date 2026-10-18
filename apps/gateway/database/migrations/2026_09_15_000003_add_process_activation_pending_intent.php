<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->boolean('systemd_activation_pending')->default(false);
            $table->boolean('proxycli_source_activation_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->dropColumn(['systemd_activation_pending', 'proxycli_source_activation_pending']);
        });
    }
};
