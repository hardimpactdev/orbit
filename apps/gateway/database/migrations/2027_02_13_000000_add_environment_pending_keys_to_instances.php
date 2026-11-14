<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keys that env:update stored on a container Instance and the next env:sync writes once (docs/cli/env/sync.mdx#on-a-container-instance). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->text('environment_pending_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('environment_pending_keys');
        });
    }
};
