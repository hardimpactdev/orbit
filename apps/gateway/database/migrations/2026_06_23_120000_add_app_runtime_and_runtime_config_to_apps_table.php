<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->renameColumn('runtime_kind', 'runtime');
        });

        Schema::table('apps', function (Blueprint $table): void {
            $table->json('runtime_config')->nullable()->after('runtime');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn('runtime_config');
        });

        Schema::table('apps', function (Blueprint $table): void {
            $table->renameColumn('runtime', 'runtime_kind');
        });
    }
};
