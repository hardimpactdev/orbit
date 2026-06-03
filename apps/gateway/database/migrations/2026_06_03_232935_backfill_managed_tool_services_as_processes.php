<?php

declare(strict_types=1);

use App\Services\Tools\ManagedServiceToolProcessBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ManagedServiceToolProcessBackfill::class)->run();
    }

    public function down(): void
    {
        // Data-only compatibility backfill. Existing process rows are left in
        // place on rollback to avoid deleting operator-owned lifecycle intent.
    }
};
