<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->string('user')->default('root');
            $table->integer('port')->default(22);
            $table->boolean('is_local')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('external_access')->default(false);
            $table->string('external_host')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('tld', 20)->nullable();
            $table->string('editor_scheme', 50)->nullable();
            $table->string('cli_version')->nullable();
            $table->string('cli_path')->nullable();
            $table->timestamp('cli_checked_at')->nullable();
            $table->string('orchestrator_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->string('status')->default('active');
            $table->json('provisioning_log')->nullable();
            $table->string('provisioning_error')->nullable();
            $table->unsignedTinyInteger('provisioning_step')->nullable();
            $table->unsignedTinyInteger('provisioning_total_steps')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
