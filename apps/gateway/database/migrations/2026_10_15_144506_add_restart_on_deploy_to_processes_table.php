<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processes', static function (Blueprint $table): void {
            $table->boolean('restart_on_deploy')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('processes', static function (Blueprint $table): void {
            $table->dropColumn('restart_on_deploy');
        });
    }
};
