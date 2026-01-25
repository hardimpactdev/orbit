<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix the deployments table foreign key after servers->environments rename.
     * SQLite doesn't support modifying foreign keys, so we recreate the table.
     */
    public function up(): void
    {
        // SQLite doesn't support ALTER FOREIGN KEY, need to recreate table
        if (DB::getDriverName() === 'sqlite') {
            // Get existing data
            $deployments = DB::table('deployments')->get();

            // Drop the old table
            Schema::dropIfExists('deployments');

            // Create new table with correct foreign key
            Schema::create('deployments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('pending');
                $table->string('local_path')->nullable();
                $table->string('url')->nullable();
                $table->string('orchestrator_id')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'environment_id']);
            });

            // Restore data with renamed column
            foreach ($deployments as $deployment) {
                DB::table('deployments')->insert([
                    'id' => $deployment->id,
                    'project_id' => $deployment->project_id,
                    'environment_id' => $deployment->server_id, // Rename server_id to environment_id
                    'status' => $deployment->status,
                    'local_path' => $deployment->local_path,
                    'url' => $deployment->site_url ?? null,
                    'orchestrator_id' => $deployment->orchestrator_id ?? null,
                    'created_at' => $deployment->created_at,
                    'updated_at' => $deployment->updated_at,
                ]);
            }
        } else {
            // For MySQL/PostgreSQL, can use standard migration
            Schema::table('deployments', function (Blueprint $table) {
                $table->dropForeign(['server_id']);
                $table->renameColumn('server_id', 'environment_id');
            });

            Schema::table('deployments', function (Blueprint $table) {
                $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $deployments = DB::table('deployments')->get();

            Schema::dropIfExists('deployments');

            Schema::create('deployments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('server_id');
                $table->string('status')->default('pending');
                $table->string('local_path')->nullable();
                $table->string('site_url')->nullable();
                $table->string('orchestrator_id')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'server_id']);
                // Note: Can't restore foreign key to servers as it no longer exists
            });

            foreach ($deployments as $deployment) {
                DB::table('deployments')->insert([
                    'id' => $deployment->id,
                    'project_id' => $deployment->project_id,
                    'server_id' => $deployment->environment_id,
                    'status' => $deployment->status,
                    'local_path' => $deployment->local_path,
                    'site_url' => $deployment->url ?? null,
                    'orchestrator_id' => $deployment->orchestrator_id ?? null,
                    'created_at' => $deployment->created_at,
                    'updated_at' => $deployment->updated_at,
                ]);
            }
        } else {
            Schema::table('deployments', function (Blueprint $table) {
                $table->dropForeign(['environment_id']);
                $table->renameColumn('environment_id', 'server_id');
            });
        }
    }
};
