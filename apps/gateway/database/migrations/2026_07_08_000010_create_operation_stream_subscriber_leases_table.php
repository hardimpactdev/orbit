<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operation_stream_subscriber_leases', static function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignUuid('operation_run_id')
                ->constrained('operation_runs')
                ->cascadeOnDelete();
            $table->string('channel');
            $table->string('subscriber');
            $table->timestamp('expires_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['operation_run_id', 'channel', 'subscriber']);
            $table->index(['operation_run_id', 'channel', 'left_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_stream_subscriber_leases');
    }
};
