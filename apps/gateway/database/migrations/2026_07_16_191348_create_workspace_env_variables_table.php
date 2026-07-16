<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workspace_env_variables', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('secret')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_env_variables');
    }
};
