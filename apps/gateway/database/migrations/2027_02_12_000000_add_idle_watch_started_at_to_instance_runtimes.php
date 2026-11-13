<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the idle sleep first watched a container Instance: no Instance sleeps before one full window of it (docs/reference/development-containers.md#sleep-and-wake). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->timestamp('idle_watch_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn('idle_watch_started_at');
        });
    }
};
