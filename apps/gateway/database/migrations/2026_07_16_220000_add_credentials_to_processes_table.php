<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('processes', static function (Blueprint $table): void {
            $table->text('credentials')->nullable()->after('runtime_config');
        });
    }

    public function down(): void
    {
        Schema::table('processes', static function (Blueprint $table): void {
            $table->dropColumn('credentials');
        });
    }
};
