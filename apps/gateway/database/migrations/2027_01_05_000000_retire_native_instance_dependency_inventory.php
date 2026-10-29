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
        Schema::dropIfExists('instance_dependency_edges');
        Schema::dropIfExists('instance_dependency_resolutions');
        Schema::dropIfExists('instance_dependency_observations');
        Schema::dropIfExists('instance_dependency_scan_attempts');
        Schema::dropIfExists('dependency_packages');
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('dependency_sub_projects');
        });
    }

    public function down(): void
    {
        Schema::create('dependency_packages', function (Blueprint $table): void {
            $table->increments('id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->string('name');
            $table->unique(['ecosystem', 'name']);
            $table->unique(['id', 'ecosystem']);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('instance_dependency_observations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->boolean('present');
            $table->dateTime('observed_at');
            $table->text('project_root');
            $table->text('source_reference')->nullable();
            $table->text('file_hashes');
            $table->string('format')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('sub_project')->default(DB::raw('\'\''));
            $table->unique(['id', 'ecosystem']);
            $table->unique(['instance_id', 'sub_project', 'ecosystem']);
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('instance_dependency_resolutions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('observation_id');
            $table->integer('dependency_package_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->text('locator');
            $table->text('version');
            $table->boolean('regular');
            $table->boolean('development');
            $table->text('source_reference')->nullable();
            $table->text('integrity')->nullable();
            $table->unique(['observation_id', 'locator']);
            $table->unique(['observation_id', 'id']);
            $table->index(['dependency_package_id', 'ecosystem']);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['observation_id', 'ecosystem'])->references(['id', 'ecosystem'])->on('instance_dependency_observations')->onDelete('cascade');
            $table->foreign(['dependency_package_id', 'ecosystem'])->references(['id', 'ecosystem'])->on('dependency_packages')->onDelete('restrict');
        });

        Schema::create('instance_dependency_edges', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('observation_id');
            $table->integer('from_resolution_id')->nullable();
            $table->integer('to_resolution_id')->nullable();
            $table->string('name');
            $table->text('constraint');
            $table->enum('kind', ['dependency', 'peer']);
            $table->enum('scope', ['regular', 'development']);
            $table->boolean('optional');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['observation_id'])->references(['id'])->on('instance_dependency_observations')->onDelete('cascade');
            $table->foreign(['observation_id', 'from_resolution_id'])->references(['observation_id', 'id'])->on('instance_dependency_resolutions')->onDelete('cascade');
            $table->foreign(['observation_id', 'to_resolution_id'])->references(['observation_id', 'id'])->on('instance_dependency_resolutions')->onDelete('cascade');
        });

        Schema::create('instance_dependency_scan_attempts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->dateTime('attempted_at');
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('sub_project')->default(DB::raw('\'\''));
            $table->string('error_detail')->nullable();
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::table('instance_dependency_edges', function (Blueprint $table): void {
            $table->index(['observation_id', 'from_resolution_id']);
            $table->index(['observation_id', 'to_resolution_id']);
        });
        DB::statement('CREATE UNIQUE INDEX dependency_edge_identity ON instance_dependency_edges (observation_id, (from_resolution_id IS NULL), COALESCE(from_resolution_id, 0), (to_resolution_id IS NULL), COALESCE(to_resolution_id, 0), name, "constraint", kind, scope, optional)');
        Schema::table('instance_dependency_scan_attempts', function (Blueprint $table): void {
            $table->index(['instance_id', 'sub_project', 'ecosystem', 'id']);
            $table->index(['instance_id', 'ecosystem', 'id']);
        });
        Schema::table('instances', function (Blueprint $table): void {
            $table->text('dependency_sub_projects')->nullable();
        });
    }
};
