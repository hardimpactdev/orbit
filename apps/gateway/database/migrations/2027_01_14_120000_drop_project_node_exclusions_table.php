<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_node_exclusions');
    }

    public function down(): void
    {
        Schema::create('project_node_exclusions', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('node_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
            $table->unique(['project_id', 'node_id']);
        });
    }
};
