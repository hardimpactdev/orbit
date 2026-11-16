<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_deployments', static function (Blueprint $table): void {
            $table->json('best_effort_steps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_deployments', static function (Blueprint $table): void {
            $table->dropColumn('best_effort_steps');
        });
    }
};
