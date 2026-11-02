<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A kind with many items, such as one row per Git checkout, names its item in `subject`; other kinds keep ''.
        Schema::table('node_facts', function (Blueprint $table): void {
            $table->string('subject', 1024)->default('')->after('kind');
            $table->dropUnique(['node_id', 'kind']);
            $table->unique(['node_id', 'kind', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::table('node_facts', function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'kind', 'subject']);
            $table->unique(['node_id', 'kind']);
            $table->dropColumn('subject');
        });
    }
};
