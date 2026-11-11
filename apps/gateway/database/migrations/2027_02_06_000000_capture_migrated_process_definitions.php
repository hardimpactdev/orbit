<?php

declare(strict_types=1);

use App\Domain\InstanceMigration\MigratedProcessDefinitions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MigratedProcessDefinitions::class)->repair();
    }

    public function down(): void
    {
        // Captured intent can have been edited or inherited; rollback must preserve it and its links.
    }
};
