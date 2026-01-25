<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->string('cli_version')->nullable()->after('tld');
            $table->string('cli_path')->nullable()->after('cli_version');
            $table->timestamp('cli_checked_at')->nullable()->after('cli_path');
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn(['cli_version', 'cli_path', 'cli_checked_at']);
        });
    }
};
