<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operation_runs', function (Blueprint $table): void {
            $table->timestamp('operation_token_consumed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('operation_runs', function (Blueprint $table): void {
            $table->dropColumn('operation_token_consumed_at');
        });
    }
};
