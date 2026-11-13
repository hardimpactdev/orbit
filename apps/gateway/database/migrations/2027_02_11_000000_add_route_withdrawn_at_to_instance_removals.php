<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a run of the removal withdrew its Route: a retry then skips the withdraw and takes no Route publication hold.
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->timestamp('route_withdrawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->dropColumn('route_withdrawn_at');
        });
    }
};
