<?php

declare(strict_types=1);

use App\Infrastructure\Database\LongWriteTransactionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('names the code that held the write lock past the threshold, once its outermost transaction ends', function (): void {
    $now = 0.0;
    (new LongWriteTransactionLog(static function () use (&$now): float {
        return $now;
    }))->register();
    $warnings = [];
    Log::listen(static function ($message) use (&$warnings): void {
        if ($message->message === 'Long SQLite write transaction') {
            $warnings[] = $message->context;
        }
    });

    DB::transaction(function () use (&$now): void {
        DB::transaction(function () use (&$now): void {
            $now += 2_500;
        });
    });
    DB::transaction(function () use (&$now): void {
        $now += 1_999;
    });

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['held_ms'])->toBe(2_500)
        ->and($warnings[0]['outcome'])->toBe('committed')
        ->and($warnings[0]['origin'])->toStartWith('tests/Feature/Infrastructure/Database/LongWriteTransactionLogTest.php:');
});
