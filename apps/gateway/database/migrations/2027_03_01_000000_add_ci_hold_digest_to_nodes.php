<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SHA-256 digest of the hold script that a CI Node's last passed ci-runner add or role converge installed. A Node offers proof places
 * only while it is the digest of the script this release installs (docs/reference/node-provisioning.md#proof-places-on-a-ci-node). Null
 * until such a converge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('ci_hold_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('ci_hold_digest');
        });
    }
};
