<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('node_roles')->where('role', 'app-development')->update(['role' => 'app-dev']);
        DB::table('node_roles')->where('role', 'app-production')->update(['role' => 'app-prod']);
    }

    public function down(): void
    {
        DB::table('node_roles')->where('role', 'app-dev')->update(['role' => 'app-development']);
        DB::table('node_roles')->where('role', 'app-prod')->update(['role' => 'app-production']);
    }
};
