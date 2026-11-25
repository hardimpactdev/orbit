<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_threads', function (Blueprint $table): void {
            $table->foreignId('instance_id')->nullable()->change();
            $table->unsignedBigInteger('orbit_project_id')->nullable();
        });
        Schema::create('conn_thread_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_environment_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id');
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique(['conn_environment_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_thread_classifications');
        DB::table('conn_threads')->whereNull('instance_id')->delete();
        Schema::table('conn_threads', function (Blueprint $table): void {
            $table->dropColumn('orbit_project_id');
            $table->foreignId('instance_id')->change();
        });
    }
};
