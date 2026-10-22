<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('instance_path_proxies');
    }

    public function down(): void
    {
        Schema::create('instance_path_proxies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('route_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('process_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('port');
            $table->string('prefix', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }
};
