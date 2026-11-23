<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A step that runs only after another step of its Operation failed, such as the stop of a copy whose create failed (`on_failure`),
 * and a step the Operation neither waits for nor fails by, such as a spare made beside a create (`optional`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_engine_steps', function (Blueprint $table): void {
            $table->boolean('on_failure')->default(false)->after('after');
            $table->boolean('optional')->default(false)->after('on_failure');
        });
    }

    public function down(): void
    {
        Schema::table('operation_engine_steps', function (Blueprint $table): void {
            $table->dropColumn(['on_failure', 'optional']);
        });
    }
};
