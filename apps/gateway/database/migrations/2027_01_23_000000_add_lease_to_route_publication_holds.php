<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hold taken for one step of a run (docs/reference/routes.md#coordinate-publication) has a lease, so a run that dies between its
 * hold and its release cannot keep a Node. A hold taken at admission has none and lasts to the terminal status.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('route_publication_holds', 'lease_until')) {
            return;
        }
        Schema::table('route_publication_holds', static function (Blueprint $table): void {
            $table->timestamp('lease_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('route_publication_holds', static function (Blueprint $table): void {
            $table->dropColumn('lease_until');
        });
    }
};
