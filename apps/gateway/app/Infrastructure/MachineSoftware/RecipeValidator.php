<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use RuntimeException;
use Throwable;

/** Availability and integrity only. RecipeLoader already enforces structure before any I/O. */
final readonly class RecipeValidator
{
    public function __construct(
        private SnapshotAvailability $snapshots = new AuthenticatedUbuntuSnapshot,
        private RecipeDownload $downloads = new RecipeDownload,
    ) {}

    public function validate(MachineRecipe $recipe, string $artifactDirectory): void
    {
        $this->snapshots->verify($recipe);
        foreach ($recipe->tools as $name => $tool) {
            foreach ($tool->artifacts as $architecture => $artifact) {
                $temporary = $tool->source !== 'internal';
                $file = null;
                try {
                    if ($temporary) {
                        $mirror = in_array($name, ['git', 'git-man'], true) ? $recipe->phpPolicy->mirror : '';
                        try {
                            $file = $this->downloads->file($mirror === '' ? $artifact->location : $mirror.'/'.$name.'_'.$artifact->digest.'.deb', redirects: true);
                        } catch (RuntimeException $exception) {
                            if ($mirror === '') {
                                throw $exception;
                            }
                            $file = $this->downloads->file($artifact->location, redirects: true);
                        }
                    } else {
                        $file = rtrim($artifactDirectory, '/').'/'.$artifact->location;
                    }
                    if (! is_file($file) || ! is_readable($file)) {
                        throw new RuntimeException("Missing or unreadable artifact file [{$file}].");
                    }
                    $actual = hash_file($artifact->algorithm, $file);
                    if ($actual !== $artifact->digest) {
                        throw new RuntimeException("{$artifact->algorithm} mismatch: expected {$artifact->digest}, got ".($actual === false ? 'unreadable bytes' : $actual).'.');
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException("Tool [{$name}] version [{$tool->version}] architecture [{$architecture}] source [{$tool->source}] artifact [{$artifact->location}]: {$exception->getMessage()}", previous: $exception);
                } finally {
                    if ($temporary && $file !== null) {
                        @unlink($file);
                    }
                }
            }
        }
    }
}
