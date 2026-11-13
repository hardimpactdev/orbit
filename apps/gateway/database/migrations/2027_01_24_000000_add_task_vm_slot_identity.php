<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A slot is a fixed, reused task VM `<host>-tvm-<number>` (docs/reference/old-paths.md#off-in-the-live-fleet). Its row keeps its number and its network
 * identity, encrypted: the WireGuard key, the SSH host key and the agent secret, made once and written again by every enrol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('slot_number')->nullable();
            $table->text('wireguard_private_key')->nullable();
            $table->text('ssh_host_private_key')->nullable();
            $table->text('agent_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropColumn(['slot_number', 'wireguard_private_key', 'ssh_host_private_key', 'agent_secret']);
        });
    }
};
