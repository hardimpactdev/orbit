<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_servers', function (Blueprint $table): void {
            $table->boolean('private_endpoints_managed')->default(false);
        });
        Schema::table('database_connection_targets', function (Blueprint $table): void {
            $table->string('network', 16)->default('wireguard');
        });
    }

    public function down(): void
    {
        Schema::table('database_connection_targets', fn (Blueprint $table) => $table->dropColumn('network'));
        Schema::table('database_servers', fn (Blueprint $table) => $table->dropColumn('private_endpoints_managed'));
    }
};
