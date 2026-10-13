<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The push of a task VM's approved commit, which a queued job runs: the commit and branch it pushes, whether it waits,
 * pushed, or failed, the reason of a failure, and when it was queued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->string('publish_commit', 64)->nullable()->after('topology_error');
            $table->string('publish_branch')->nullable()->after('publish_commit');
            $table->string('publish_state')->nullable()->after('publish_branch');
            $table->text('publish_error')->nullable()->after('publish_state');
            $table->timestamp('publish_queued_at')->nullable()->after('publish_error');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn(['publish_commit', 'publish_branch', 'publish_state', 'publish_error', 'publish_queued_at']);
        });
    }
};
