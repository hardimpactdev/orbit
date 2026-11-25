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
            $table->json('desired_repository')->nullable();
        });
        DB::table('tools')->whereNotNull('apt_repository_id')->update([
            'desired_repository' => DB::table('apt_repositories')->select('definition')->whereColumn('apt_repositories.id', 'tools.apt_repository_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn('desired_repository');
        });
    }
};
