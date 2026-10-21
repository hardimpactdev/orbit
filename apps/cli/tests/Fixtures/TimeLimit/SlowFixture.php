<?php

declare(strict_types=1);

namespace Tests\Fixtures\TimeLimit;

use PHPUnit\Framework\TestCase;

abstract class SlowFixture extends TestCase
{
    public function test_fast(): void
    {
        self::assertTrue(true);
    }

    public function test_slow(): void
    {
        $deadline = hrtime(true) + 3_000_000_000;

        do {
            hash('sha256', 'bounded work beyond the ordinary per-test limit');
        } while (hrtime(true) < $deadline);

        self::assertTrue(true);
    }
}
