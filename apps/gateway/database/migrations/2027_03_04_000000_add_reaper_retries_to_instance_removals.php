<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The removal reaper's starts of a failed destroy (InstanceRemovalReaper), and when it reported the removal stuck. The
        // removal's immutable-contract trigger checks neither column, so a failed or removing removal may change them.
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('stuck_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->dropColumn(['retry_count', 'stuck_at']);
        });
    }
};
