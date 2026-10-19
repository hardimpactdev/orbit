<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckOutputBuilder;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;

it('bounds ordinary raw input at a whole line and preserves the failure tail', function (): void {
    $builder = new TaskCheckOutputBuilder(new CommandActivityInputSanitizer);
    $raw = str_repeat("ordinary line\n", 20_000)."FAILED last line\n";

    $tail = $builder->tail($raw);

    expect($tail)->toContain('FAILED last line')
        ->and(strlen($tail))->toBeLessThanOrEqual(16 * 1024);
});

it('drops a partial first raw line rather than preserving an unkeyed secret fragment', function (): void {
    $builder = new TaskCheckOutputBuilder(new CommandActivityInputSanitizer);

    expect($builder->tail('TOKEN='.str_repeat('s', 200_000)."\nFAILED last line"))->toBe('FAILED last line')
        ->and($builder->tail(str_repeat('x', 200_000).'FAILED'))->toBe(str_repeat('x', 16 * 1024 - 6).'FAILED');
});

it('drops a cut PEM body before redacting later keys in the same window', function (string $end): void {
    $builder = new TaskCheckOutputBuilder(new CommandActivityInputSanitizer);
    $key = "-----BEGIN PRIVATE KEY-----\n".str_repeat(str_repeat('A', 64)."\n", 50).$end."\n";
    $raw = str_repeat("ordinary line\n", 5000).str_repeat($key, 40)."FAILED last line\n";

    $tail = $builder->tail($raw);

    expect($tail)->toContain('[REDACTED]', 'FAILED last line')->not->toContain('AAAA');
    expect($builder->buildStream(str_split($raw, 65_536)))->toBe($builder->build($tail));
})->with(['plain END' => ['-----END PRIVATE KEY-----'], 'ANSI END' => ["-----END PRIVATE\x1b[0m KEY-----"]]);

it('preserves a complete secret line exactly at the raw window boundary', function (bool $exact): void {
    $builder = new TaskCheckOutputBuilder(new CommandActivityInputSanitizer);
    $suffix = 'TOKEN='.str_repeat('s', 100 * 1024 - strlen("TOKEN=\nFAILED last line"))."\nFAILED last line";
    $raw = ($exact ? "older\n" : 'older').$suffix;

    expect($builder->tail($raw))->toBe($exact ? "TOKEN=[REDACTED]\nFAILED last line" : 'FAILED last line');
})->with([true, false]);

it('uses the same line window for direct and streamed output across arbitrary chunks', function (int $chunkBytes): void {
    $builder = new TaskCheckOutputBuilder(new CommandActivityInputSanitizer);
    $raw = str_repeat("🪐 old output\n", 20_000).'TOKEN='.str_repeat('s', 40_000)."\nFAILED last line";

    expect($builder->buildStream(str_split($raw, $chunkBytes)))->toBe($builder->build($builder->tail($raw)))
        ->toContain('TOKEN=[REDACTED]', 'FAILED last line');
})->with([1, 65_536, 102_400, 200_000]);

it('quotes stored redacted text without a second redaction pass', function (): void {
    $builder = new TaskCheckOutputBuilder(new CommandActivityInputSanitizer);
    $stored = $builder->tail('--token="v"name"');

    expect($stored)->toBe('--token=[REDACTED]name"')
        ->and($builder->build($stored))->toBe("```\n".$stored."\n```");
});
