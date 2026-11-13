<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The last renewal of a container's Gateway session: its refusal code and when it ended (docs/reference/conn.md#instance-servers). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->string('renewal_error_code')->nullable();
            $table->timestamp('renewal_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->dropColumn(['renewal_error_code', 'renewal_checked_at']);
        });
    }
};
