<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->string('external_id_admission')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->dropColumn('external_id_admission');
        });
    }
};
