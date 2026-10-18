<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Retired slug used only by this historical migration. */
    private const string RetiredSlug = 'proxycli';

    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->boolean('systemd_activation_pending')->default(false);
            $table->boolean(self::RetiredSlug.'_source_activation_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->dropColumn(['systemd_activation_pending', self::RetiredSlug.'_source_activation_pending']);
        });
    }
};
