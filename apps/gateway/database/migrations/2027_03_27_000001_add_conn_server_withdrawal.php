<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_server_nodes', static function (Blueprint $table): void {
            $table->boolean('tool_withdrawn')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('conn_server_nodes', static function (Blueprint $table): void {
            $table->dropColumn('tool_withdrawn');
        });
    }
};
