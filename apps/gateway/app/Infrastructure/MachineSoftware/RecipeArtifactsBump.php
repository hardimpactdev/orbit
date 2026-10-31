<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Updates the complete authenticated Sury and Incus artifact sets. It runs the repository's `bin/recipe-artifacts bump`,
 * the shared signed-index implementation for both providers, on a temporary copy.
 */
class RecipeArtifactsBump
{
    /** @return array<string, array<mixed>> */
    private function pins(string $recipe): array
    {
        $decoded = new RecipeLoader()->decode($recipe);
        $names = RecipeLoader::IncusPackages;
        foreach ($decoded->php as $build) {
            $names = [...$names, ...$build->packages];
        }
        $data = json_decode($recipe, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('Artifact bump has no tool pins.');
        }
        $pins = [];
        foreach (array_unique($names) as $name) {
            $pin = $data['tools'][$name] ?? null;
            if (! is_array($pin)) {
                throw new RuntimeException('Artifact bump has an invalid pin.');
            }
            $pins[$name] = $pin;
        }
        ksort($pins);

        return $pins;
    }

    public function fingerprint(string $recipe): string
    {
        return substr(hash('sha256', json_encode($this->pins($recipe), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 12);
    }

    /** Reject changes outside the authenticated package sets, even if the updater returns them. */
    public function validateChange(string $before, string $after): void
    {
        $old = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode($after, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($old) || ! is_array($old['tools'] ?? null) || ! is_array($new) || ! is_array($new['tools'] ?? null)) {
            throw new RuntimeException('Artifact bump has no tool pins.');
        }
        $pins = $this->pins($before);
        foreach ($pins as $name => $pin) {
            $candidate = $new['tools'][$name] ?? null;
            if (! is_array($candidate) || $candidate['source'] !== $pin['source'] || array_keys($candidate) !== array_keys($pin)) {
                throw new RuntimeException('Artifact bump changed the package contract.');
            }
            $new['tools'][$name] = $pin;
        }
        if ($old !== $new || $before === $after || $this->pins($before) === $this->pins($after)) {
            throw new RuntimeException('Artifact bump must change only the authenticated package pins.');
        }
    }

    /** @return string The recipe document with new pins, or the same bytes when neither provider has a newer build. */
    public function bump(string $recipe): string
    {
        return $this->update($recipe, false);
    }

    /** Authenticate the newest pins using signed indexes, without fetching Debian files. */
    public function indexes(string $recipe): string
    {
        return $this->update($recipe, true);
    }

    private function update(string $recipe, bool $indexesOnly): string
    {
        $file = tempnam(sys_get_temp_dir(), 'orbit-artifact-bump-');
        if ($file === false) {
            throw new RuntimeException('The recipe artifact bump could not create a temporary recipe.');
        }
        try {
            file_put_contents($file, $recipe);
            $process = new Process(['python3', base_path('../../bin/recipe-artifacts'), 'bump', '--recipe', $file, ...($indexesOnly ? ['--indexes-only'] : [])]);
            $process->setTimeout(600);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('The recipe artifact bump failed: '.mb_substr(trim($process->getErrorOutput()), 0, 1000));
            }
            $updated = file_get_contents($file);
            if ($updated === false) {
                throw new RuntimeException('The recipe artifact bump lost its recipe.');
            }

            return $updated;
        } finally {
            @unlink($file);
        }
    }
}
