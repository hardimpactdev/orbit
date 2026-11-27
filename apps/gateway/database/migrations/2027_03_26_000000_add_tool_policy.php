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
        Schema::table('tools', static function (Blueprint $table): void {
            $table->string('desired_version')->nullable();
        });
        Schema::create('tool_policy', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('mode');
            $table->text('catalog_path')->nullable();
        });
        DB::table('tool_policy')->insert(['id' => 1, 'mode' => 'database']);
        Schema::create('tool_policy_snapshots', static function (Blueprint $table): void {
            $table->id();
            $table->text('document');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_policy_snapshots');
        Schema::dropIfExists('tool_policy');
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn('desired_version');
        });
    }
};
