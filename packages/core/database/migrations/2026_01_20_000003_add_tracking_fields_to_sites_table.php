<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check which columns already exist
        $columns = Schema::getColumnListing('sites');

        Schema::table('sites', function (Blueprint $table) use ($columns) {
            // Add environment_id as nullable first (for existing data)
            if (! in_array('environment_id', $columns)) {
                $table->unsignedBigInteger('environment_id')->nullable()->after('id');
            }
            if (! in_array('name', $columns)) {
                $table->string('name')->nullable()->after('environment_id');
            }
            if (! in_array('display_name', $columns)) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (! in_array('github_repo', $columns)) {
                $table->string('github_repo')->nullable();
            }
            if (! in_array('site_type', $columns)) {
                $table->string('site_type')->nullable();
            }
            if (! in_array('has_public_folder', $columns)) {
                $table->boolean('has_public_folder')->default(false);
            }
            if (! in_array('domain', $columns)) {
                $table->string('domain')->nullable();
            }
            if (! in_array('site_url', $columns)) {
                $table->string('site_url')->nullable();
            }
            if (! in_array('status', $columns)) {
                $table->string('status')->default('active');
            }
            if (! in_array('error_message', $columns)) {
                $table->text('error_message')->nullable();
            }
            if (! in_array('job_id', $columns)) {
                $table->uuid('job_id')->nullable();
            }
        });

        // Set default values for existing rows
        // Use first environment or create one
        $envId = DB::table('environments')->value('id');
        if (! $envId) {
            $envId = DB::table('environments')->insertGetId([
                'name' => 'Local',
                'host' => 'localhost',
                'is_local' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update existing sites with default environment and name from slug
        DB::table('sites')
            ->whereNull('environment_id')
            ->update(['environment_id' => $envId]);

        DB::table('sites')
            ->whereNull('name')
            ->update(['name' => DB::raw('slug')]);

        DB::table('sites')
            ->where('status', 'queued')
            ->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Additive only
    }
};
