<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->unsignedBigInteger('cleanup_version')->default(0);
            $table->unsignedBigInteger('cleanup_completed_version')->default(0);
        });
    }
};
