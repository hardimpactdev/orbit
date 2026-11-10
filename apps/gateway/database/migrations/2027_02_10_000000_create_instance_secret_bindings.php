<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secret bindings (ADR 0254, docs/reference/development-containers.md#secret-bindings): a mark on one stored environment key of a
 * container Instance, with the hosts and headers where the Node's egress proxy may inject its value. The value stays in
 * instance_environment_values; this table holds no secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_secret_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('instances')->cascadeOnDelete();
            $table->string('env_key');
            $table->json('hosts');
            $table->json('headers');
            // A clone shares its default's placeholders; a placeholder names a value only in its own container's Node file.
            $table->string('placeholder');
            $table->timestamps();
            $table->unique(['instance_id', 'env_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_secret_bindings');
    }
};
