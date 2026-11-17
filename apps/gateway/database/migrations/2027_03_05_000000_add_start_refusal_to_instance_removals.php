<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The error code of the removal reaper's last refused start (InstanceRemovalReaper); null once a start is admitted. Doctor
        // reads it, so a refusal is visible at once. The removal's immutable-contract trigger does not check this column.
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->string('start_refusal')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->dropColumn('start_refusal');
        });
    }
};
