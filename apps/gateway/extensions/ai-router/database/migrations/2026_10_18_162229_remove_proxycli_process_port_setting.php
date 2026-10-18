<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Retired slug used only by this historical migration. */
    private const string RetiredSlug = 'proxycli';

    public function up(): void
    {
        DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)
            ->where('key', self::RetiredSlug.'.process_port')->delete();
    }

    public function down(): void {}
};
