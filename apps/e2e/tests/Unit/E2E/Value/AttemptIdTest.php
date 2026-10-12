<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;

describe('AttemptId', function (): void {
    it('generates exactly 32 lowercase hexadecimal characters', function (): void {
        expect((string) AttemptId::generate())->toMatch('/\A[0-9a-f]{32}\z/D');
    });

    it('generates a distinct identity per attempt', function (): void {
        expect(AttemptId::generate()->value)->not->toBe(AttemptId::generate()->value);
    });

    it('rejects anything but exact 32 lowercase hexadecimal characters', function (string $value): void {
        expect(fn () => new AttemptId($value))->toThrow(InvalidArgumentException::class);
    })->with([
        'issue identity' => ['TST-123'],
        'empty' => [''],
        'uppercase hexadecimal' => [str_repeat('A', 32)],
        'too short' => [str_repeat('a', 31)],
        'too long' => [str_repeat('a', 33)],
        'trailing newline' => [str_repeat('a', 32)."\n"],
        'non hexadecimal' => [str_repeat('g', 32)],
    ]);

    it('keeps a short readable prefix of the exact identity', function (): void {
        expect(new AttemptId(str_repeat('a', 24).str_repeat('b', 8))->short())->toBe(str_repeat('a', 8));
    });
});

describe('attempt purpose', function (): void {
    it('names discovery as the only purpose', function (): void {
        expect(AttemptPurpose::cases())->toBe([AttemptPurpose::Discovery])
            ->and(AttemptPurpose::Discovery->value)->toBe('discovery');
    });
});
