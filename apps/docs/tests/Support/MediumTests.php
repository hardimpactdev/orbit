<?php

declare(strict_types=1);

namespace Tests\Support;

use Pest\Factories\Attribute;
use Pest\TestSuite;
use PHPUnit\Framework\Attributes\Medium;

final class MediumTests
{
    public static function in(string $filename): void
    {
        $testClass = TestSuite::getInstance()->tests->get($filename);

        if ($testClass !== null) {
            $testClass->attributes[] = new Attribute(Medium::class, []);
        }
    }
}
