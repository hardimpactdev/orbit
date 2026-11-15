<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A removal's share of the Nodes that serve its Route (docs/reference/routes.md#coordinate-publication): removals hold a Node
 * together, a publisher's row in route_publication_holds holds it alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('route_withdrawal_holds')) {
            return;
        }
        Schema::create('route_withdrawal_holds', static function (Blueprint $table): void {
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('operation_id')->index();
            $table->timestamp('created_at')->nullable();
            $table->primary(['node_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_withdrawal_holds');
    }
};
