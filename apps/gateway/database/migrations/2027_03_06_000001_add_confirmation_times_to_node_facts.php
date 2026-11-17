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
        Schema::table('node_facts', function (Blueprint $table): void {
            $table->timestamp('confirmed_at', 3)->nullable();
            $table->timestamp('root_confirmed_at', 3)->nullable();
        });

        DB::table('node_facts')->whereIn('kind', ['host', 'units', 'ufw'])
            ->update(['confirmed_at' => DB::raw('observed_at')]);
    }

    public function down(): void
    {
        Schema::table('node_facts', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'root_confirmed_at']);
        });
    }
};
