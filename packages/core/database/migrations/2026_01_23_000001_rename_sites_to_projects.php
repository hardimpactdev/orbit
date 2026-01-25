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
        // Rename the table
        Schema::rename('sites', 'projects');

        // Rename columns
        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('site_type', 'project_type');
            $table->renameColumn('site_url', 'url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename columns back
        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('project_type', 'site_type');
            $table->renameColumn('url', 'site_url');
        });

        // Rename table back
        Schema::rename('projects', 'sites');
    }
};
