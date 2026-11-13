<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every existing row gets false: nothing proves its runs left the Node alone. Only a first
        // `node:role:add` after this migration starts a row with true.
        Schema::table('node_roles', function (Blueprint $table): void {
            $table->boolean('node_untouched')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('node_roles', function (Blueprint $table): void {
            $table->dropColumn('node_untouched');
        });
    }
};
