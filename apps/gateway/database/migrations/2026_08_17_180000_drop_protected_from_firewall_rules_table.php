<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasIndex('firewall_rules', ['node_id', 'owner', 'protected'])) {
            Schema::table('firewall_rules', static function (Blueprint $table): void {
                $table->dropIndex(['node_id', 'owner', 'protected']);
            });
        }

        if (! Schema::hasColumn('firewall_rules', 'protected')) {
            return;
        }

        Schema::table('firewall_rules', static function (Blueprint $table): void {
            $table->dropColumn('protected');
        });
    }
};
