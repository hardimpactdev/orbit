<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Database user keeps its password, encrypted, so the Gateway can publish it. vault_items records
 * each item the Gateway published to Vaultwarden, keyed by its source record
 * ([ADR 0205](/decisions/0205-publish-database-credentials-to-vaultwarden)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_users', function (Blueprint $table): void {
            $table->text('password')->nullable()->after('username');
        });

        Schema::create('vault_items', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->unique();
            $table->string('cipher_id');
            $table->string('fingerprint', 64);
            $table->string('revision_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_items');

        Schema::table('database_users', function (Blueprint $table): void {
            $table->dropColumn('password');
        });
    }
};
