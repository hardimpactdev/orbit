<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upcloud_task_vm_image_builds', function (Blueprint $table): void {
            $table->id();
            $table->string('recipe_identity')->index();
            $table->string('state')->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->uuid('template_uuid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upcloud_task_vm_image_builds');
    }
};
