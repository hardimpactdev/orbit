<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The isolated ID map size of a nested-fleet container (docs/reference/development-containers.md#the-id-map-size). Every nested row
 * that exists now was made with 101,000,000 IDs; a normal container records none and keeps Incus's 65,536.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->unsignedInteger('idmap_size')->nullable();
        });
        DB::table('instance_runtimes')->where('incus_project', 'orbit-instances-nested')->update(['idmap_size' => 101000000]);
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn('idmap_size');
        });
    }
};
