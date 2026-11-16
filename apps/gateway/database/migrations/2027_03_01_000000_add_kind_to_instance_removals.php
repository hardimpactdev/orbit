<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which command owns the removal: destroy deletes the source, unregister keeps it. A removal keeps its kind on every retry.
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->string('kind', 16)->default('destroy');
        });
    }

    public function down(): void
    {
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
