<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile's settings document is split into sections that each carry their own version, so a phone
 * font change no longer conflicts with a Mac theme change. `section_meta` holds `{section: {version,
 * updated_at, updated_by_node_id}}` for the sections that were written since. Existing rows keep a null
 * value, and a section without an entry is at version 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_profiles', static function (Blueprint $table): void {
            $table->json('section_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conn_profiles', static function (Blueprint $table): void {
            $table->dropColumn('section_meta');
        });
    }
};
