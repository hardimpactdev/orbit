<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->json('sources')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->dropColumn('sources');
        });
    }
};
