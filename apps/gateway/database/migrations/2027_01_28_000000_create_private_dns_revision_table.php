<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row: the revision of the last private DNS render. The phase on the vpn Node installs only a newer one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_dns_revision', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_dns_revision');
    }
};
