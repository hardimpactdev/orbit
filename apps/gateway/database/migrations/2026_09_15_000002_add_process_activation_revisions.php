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
            $table->string('systemd_activation_hash', 64)->nullable();
            $table->string('proxycli_source_revision', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->dropColumn(['systemd_activation_hash', 'proxycli_source_revision']);
        });
    }
};
