<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Moves every Sury PHP pin of a recipe document to Sury's newest build. It runs the repository's `bin/php-build bump`,
 * the one implementation that checks Sury's signed index (ADR 0212), on a temporary copy.
 */
class PhpBuildBump
{
    /** @return string The recipe document with new pins, or the same bytes when Sury has no newer build. */
    public function bump(string $recipe): string
    {
        $file = tempnam(sys_get_temp_dir(), 'orbit-php-bump-');
        if ($file === false) {
            throw new RuntimeException('The PHP build bump could not create a temporary recipe.');
        }
        try {
            file_put_contents($file, $recipe);
            $process = new Process(['python3', base_path('../../bin/php-build'), 'bump', '--recipe', $file]);
            $process->setTimeout(300);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('The PHP build bump failed: '.mb_substr(trim($process->getErrorOutput()), 0, 1000));
            }
            $updated = file_get_contents($file);
            if ($updated === false) {
                throw new RuntimeException('The PHP build bump lost its recipe.');
            }

            return $updated;
        } finally {
            @unlink($file);
        }
    }
}
