<?php

declare(strict_types=1);

namespace App\Providers;

use Laravel\Boost\Install\GuidelineComposer;
use Orbit\Boost\GuidelineSections;

/** Drops the stock Boost sections that the Gateway and E2E do not use ({@see GuidelineSections}). */
final class E2EGuidelineComposer extends GuidelineComposer
{
    public function compose(): string
    {
        return GuidelineSections::withoutRemoved(parent::compose());
    }
}
