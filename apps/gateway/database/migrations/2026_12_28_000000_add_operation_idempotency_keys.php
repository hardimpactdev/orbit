<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', static function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->string('idempotency_fingerprint', 64)->nullable();
            $table->unique(['kind', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('operations', static function (Blueprint $table): void {
            $table->dropUnique(['kind', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
