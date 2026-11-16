<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The digest of each converge phase that the Node's last succeeded `node:converge` ran or found unchanged. The next converge skips a phase
 * whose digest is equal (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change). Null until such a converge; the
 * admission of every Operation that takes the Node's admission lock clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->json('converge_digests')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('converge_digests');
        });
    }
};
