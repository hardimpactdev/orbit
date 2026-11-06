<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_review_rounds', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_group_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('head_sha', 64);
            $table->unsignedTinyInteger('round');
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('verdict', 16)->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->unique(['task_group_id', 'head_sha']);
            $table->unique(['task_group_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_review_rounds');
    }
};
