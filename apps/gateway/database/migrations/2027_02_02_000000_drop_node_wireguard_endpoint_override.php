<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recorded lan_ip selects a Node's hub endpoint (ADR 0251), so the per-Node WireGuard endpoint override goes. The one stored
 * value, app-prod's hub LAN address, is what the LAN rule renders. The drop holds when it ran before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nodes', 'wireguard_endpoint_override')) {
            Schema::table('nodes', static function (Blueprint $table): void {
                $table->dropColumn('wireguard_endpoint_override');
            });
        }
    }
};
