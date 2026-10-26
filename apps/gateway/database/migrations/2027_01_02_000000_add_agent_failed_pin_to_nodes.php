<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            // The agent pin whose installation failed on this Node; commands refuse until node:converge installs one.
            $table->string('agent_failed_pin', 64)->nullable();
        });
    }
};
