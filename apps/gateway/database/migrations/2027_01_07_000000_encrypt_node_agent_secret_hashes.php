<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite strings already have unbounded text storage; rebuilding nodes would discard its triggers.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('nodes', function (Blueprint $table): void {
                $table->text('agent_secret_hash')->nullable()->change();
            });
        }
        DB::transaction(function (): void {
            DB::table('nodes')->whereNotNull('agent_secret_hash')->orderBy('id')->chunkById(100, function ($nodes): void {
                foreach ($nodes as $node) {
                    DB::table('nodes')->where('id', $node->id)->update(['agent_secret_hash' => Crypt::encryptString($node->agent_secret_hash)]);
                }
            });
        });
    }
};
