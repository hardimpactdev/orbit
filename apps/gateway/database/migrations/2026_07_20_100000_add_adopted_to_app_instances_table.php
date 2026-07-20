<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->boolean('adopted')->default(false)->after('driver_config');
        });

        DB::table('apps')
            ->select(['id', 'adopted'])
            ->orderBy('id')
            ->each(static function (object $project): void {
                /** @var object{id: int, adopted: bool|int} $project */
                DB::table('app_instances')
                    ->where('app_id', $project->id)
                    ->update(['adopted' => (bool) $project->adopted]);
            });
    }

    public function down(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('adopted');
        });
    }
};
