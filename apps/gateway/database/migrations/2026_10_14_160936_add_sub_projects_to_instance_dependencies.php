<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_dependency_observations', function (Blueprint $table): void {
            $table->string('sub_project')->default('');
            $table->dropUnique('dependency_observation_instance_ecosystem');
            $table->unique(['instance_id', 'sub_project', 'ecosystem'], 'dependency_observation_instance_source_ecosystem');
        });
        Schema::table('instance_dependency_scan_attempts', function (Blueprint $table): void {
            $table->string('sub_project')->default('');
            $table->index(['instance_id', 'sub_project', 'ecosystem', 'id'], 'dependency_attempt_source_latest');
        });
        Schema::table('instances', function (Blueprint $table): void {
            $table->json('dependency_sub_projects')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', fn (Blueprint $table) => $table->dropColumn('dependency_sub_projects'));
        Schema::table('instance_dependency_scan_attempts', function (Blueprint $table): void {
            $table->dropIndex('dependency_attempt_source_latest');
            $table->dropColumn('sub_project');
        });
        // Source-scoped observations cannot be collapsed into a root graph.
        DB::table('instance_dependency_observations')->where('sub_project', '!=', '')->delete();
        DB::table('instance_dependency_scan_attempts')->where('sub_project', '!=', '')->delete();
        Schema::table('instance_dependency_observations', function (Blueprint $table): void {
            $table->dropUnique('dependency_observation_instance_source_ecosystem');
            $table->dropColumn('sub_project');
            $table->unique(['instance_id', 'ecosystem'], 'dependency_observation_instance_ecosystem');
        });
    }
};
