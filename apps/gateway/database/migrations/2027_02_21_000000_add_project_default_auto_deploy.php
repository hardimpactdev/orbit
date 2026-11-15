<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one switch for the automatic deploy of a Project's container default (docs/reference/deployments.md#automatic-development-defaults).
 * On by default for every Project; the previous release ignores the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', static function (Blueprint $table): void {
            $table->boolean('default_auto_deploy')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('projects', static function (Blueprint $table): void {
            $table->dropColumn('default_auto_deploy');
        });
    }
};
