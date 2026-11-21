<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A nested-fleet clone's fleet, which `instance.fleet` waits for after the create: when it became ready, or when its wait failed and
 * with which code (docs/reference/development-containers.md#a-clone-starts-its-fleet). Both stay null while it boots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->timestamp('fleet_ready_at')->nullable();
            $table->timestamp('fleet_failed_at')->nullable();
            $table->string('fleet_error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn(['fleet_ready_at', 'fleet_failed_at', 'fleet_error_code']);
        });
    }
};
