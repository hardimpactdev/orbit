<?php

declare(strict_types=1);

namespace App\Providers;

use Laravel\Boost\Install\GuidelineComposer;
use RuntimeException;

/**
 * Removes stock Boost guidance that does not fit this console harness or contradicts Orbit policy:
 * frontend bundling advice, and the rule that bans documentation the change needs.
 */
final class E2EGuidelineComposer extends GuidelineComposer
{
    public function compose(): string
    {
        $guidelines = preg_replace(
            pattern: '/\n## (?:Frontend Bundling|Vite Error|Documentation Files)\n.*?(?=\n(?:## |=== )|\z)/s',
            replacement: '',
            subject: parent::compose(),
        );

        if (! is_string($guidelines)) {
            throw new RuntimeException('Could not remove unsupported guidance.');
        }

        return $guidelines;
    }
}
