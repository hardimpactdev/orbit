<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('local_gateway_settings', 'singleton_key')) {
            Schema::table('local_gateway_settings', static function (Blueprint $table): void {
                $table->string('singleton_key')->nullable()->default(LocalGatewaySettings::SINGLETON_KEY);
            });
        }

        $this->consolidateToCanonicalRow();

        if ($this->singletonKeyIsNullable()) {
            Schema::table('local_gateway_settings', static function (Blueprint $table): void {
                $table
                    ->string('singleton_key')
                    ->default(LocalGatewaySettings::SINGLETON_KEY)
                    ->nullable(false)
                    ->change();
            });
        }

        if (! Schema::hasIndex('local_gateway_settings', ['singleton_key'], 'unique')) {
            Schema::table('local_gateway_settings', static function (Blueprint $table): void {
                $table->unique('singleton_key', 'local_gateway_settings_singleton_key_unique');
            });
        }
    }

    private function singletonKeyIsNullable(): bool
    {
        /** @var list<array{name: string, nullable: bool}> $columns */
        $columns = Schema::getColumns('local_gateway_settings');

        foreach ($columns as $column) {
            if ($column['name'] === 'singleton_key') {
                return $column['nullable'];
            }
        }

        return false;
    }

    private function consolidateToCanonicalRow(): void
    {
        $winner = DB::table('local_gateway_settings')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($winner === null) {
            return;
        }

        $canonical = DB::table('local_gateway_settings')
            ->orderBy('id')
            ->first();

        if ($canonical === null) {
            return;
        }

        DB::table('local_gateway_settings')
            ->where('id', $canonical->id)
            ->update([
                'gateway_url' => $winner->gateway_url,
                'gateway_wg_ip' => $winner->gateway_wg_ip,
                'ca_sha256' => $winner->ca_sha256,
                'ca_pem_path' => $winner->ca_pem_path,
                'trusted_at' => $winner->trusted_at,
                'singleton_key' => LocalGatewaySettings::SINGLETON_KEY,
            ]);

        DB::table('local_gateway_settings')
            ->where('id', '!=', $canonical->id)
            ->delete();
    }
};
