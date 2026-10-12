<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The size of a task VM, decided at the claim: `normal` takes one slot of its host, `large`, for a group with a
 * declared topology, takes three. Every existing row is a normal VM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->string('size')->default('normal')->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn('size');
        });
    }
};
