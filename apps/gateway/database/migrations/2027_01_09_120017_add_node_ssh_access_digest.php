<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_footprints', function (Blueprint $table): void {
            $table->string('ssh_access_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_footprints', function (Blueprint $table): void {
            $table->dropColumn('ssh_access_digest');
        });
    }
};
