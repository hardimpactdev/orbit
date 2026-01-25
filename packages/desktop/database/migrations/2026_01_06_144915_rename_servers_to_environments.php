<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename the servers table to environments
        Schema::rename('servers', 'environments');

        // Update foreign key in deployments table
        Schema::table('deployments', function (Blueprint $table) {
            $table->renameColumn('server_id', 'environment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename back to servers
        Schema::rename('environments', 'servers');

        // Update foreign key back
        Schema::table('deployments', function (Blueprint $table) {
            $table->renameColumn('environment_id', 'server_id');
        });
    }
};
