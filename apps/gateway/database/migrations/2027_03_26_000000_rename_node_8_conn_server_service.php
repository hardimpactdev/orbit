<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameService('com.nckrtl.t3-headless', 'com.nckrtl.conn-server');
    }

    public function down(): void
    {
        $this->renameService('com.nckrtl.conn-server', 'com.nckrtl.t3-headless');
    }

    private function renameService(string $from, string $to): void
    {
        DB::table('conn_server_nodes')
            ->where('node_id', 8)
            ->where('service', $from)
            ->update(['service' => $to]);
    }
};
