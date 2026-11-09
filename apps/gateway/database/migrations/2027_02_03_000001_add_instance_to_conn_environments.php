<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The T3 server of a container Instance names its Instance (docs/reference/conn.md#registration). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->foreignId('instance_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->dropUnique(['instance_id']);
            $table->dropConstrainedForeignId('instance_id');
        });
    }
};
