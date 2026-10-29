<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_pairings', function (Blueprint $table): void {
            $table->string('pairing_link_id')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
            $table->text('credential')->nullable();
            $table->timestamp('issue_started_at')->nullable();
        });
    }
};
