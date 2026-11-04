<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A slot is a long-lived task VM row (docs/reference/compute-drivers.md, Task VM slots). A row with no slot is
        // a task VM as before; nothing else changes for it.
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('slot')->nullable();
            $table->string('identity_revision', 64)->nullable();
            $table->string('bridge_address', 45)->nullable();
            $table->string('bridge_mac', 17)->nullable();
            $table->unique(['host_node_id', 'slot'], 'task_vms_host_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropUnique('task_vms_host_slot_unique');
            $table->dropColumn(['slot', 'identity_revision', 'bridge_address', 'bridge_mac']);
        });
    }
};
