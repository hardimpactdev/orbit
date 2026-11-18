<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_profile_bindings', function (Blueprint $table): void {
            $table->string('event_key', 32)->nullable();
            $table->dateTime('event_key_issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conn_profile_bindings', function (Blueprint $table): void {
            $table->dropColumn(['event_key', 'event_key_issued_at']);
        });
    }
};
