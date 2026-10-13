<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the publish job of a task VM started, from which Orbit measures a lost publish. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->timestamp('publish_started_at')->nullable()->after('publish_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn('publish_started_at');
        });
    }
};
