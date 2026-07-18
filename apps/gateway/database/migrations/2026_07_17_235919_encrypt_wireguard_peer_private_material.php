<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wireguard_peers', static function (Blueprint $table): void {
            $table->text('pre_shared_key')->nullable()->change();
        });

        DB::table('wireguard_peers')
            ->select(['id', 'private_key', 'pre_shared_key'])
            ->lazyById()
            ->each(function (object $peer): void {
                /** @var object{id: int, private_key: string, pre_shared_key: ?string} $peer */
                DB::table('wireguard_peers')
                    ->where('id', $peer->id)
                    ->update([
                        'private_key' => $this->encryptPlaintext($peer->private_key),
                        'pre_shared_key' => $peer->pre_shared_key === null
                            ? null
                            : $this->encryptPlaintext($peer->pre_shared_key),
                    ]);
            });
    }

    private function encryptPlaintext(string $value): string
    {
        try {
            Crypt::decryptString($value);
        } catch (DecryptException) {
            return Crypt::encryptString($value);
        }

        return $value;
    }
};
