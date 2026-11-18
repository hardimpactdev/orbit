<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('executor_result_received_us')->nullable();
        });

        Schema::create('executor_wake_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->string('type');
            $table->string('channel');
            $table->json('notice');
            $table->boolean('reconcile_conn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executor_wake_notices');
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropColumn('executor_result_received_us');
        });
    }
};
