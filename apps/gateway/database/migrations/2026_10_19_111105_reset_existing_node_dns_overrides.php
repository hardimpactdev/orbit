<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('nodes')->whereNotNull('dns_server_override')->update(['dns_server_override' => null]);
    }
};
