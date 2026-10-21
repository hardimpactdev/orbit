<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websocket_app_targets', function (Blueprint $table): void {
            $table->foreignId('route_id')->nullable()->constrained('routes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websocket_app_targets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('route_id');
        });
    }
};
