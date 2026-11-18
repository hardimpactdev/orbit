<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_deploy_steps', function (Blueprint $table): void {
            $table->boolean('required')->default(true);
        });
        Schema::table('instances', function (Blueprint $table): void {
            $table->timestamp('production_release_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_deploy_steps', function (Blueprint $table): void {
            $table->dropColumn('required');
        });
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('production_release_started_at');
        });
    }
};
