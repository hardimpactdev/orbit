<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\DocsImpact;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class DocsImpactCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:docs-impact {--base=} {--paths=*} {--gate}';

    #[\Override]
    protected $description = 'Report documentation pages and generators affected by repository changes.';

    /** @throws JsonException */
    public function handle(): int
    {
        $docsPath = config('librarian.path');
        if (! is_string($docsPath)) {
            $this->error('The documentation repository path is not configured.');

            return self::FAILURE;
        }
        $root = dirname($docsPath);
        $base = $this->input->getOption('base');
        $paths = $this->input->getOption('paths');
        if ($base !== null && (! is_string($base) || trim($base) === '')) {
            $this->error('The --base commit must be a non-empty string.');

            return self::FAILURE;
        }
        if (! is_array($paths)) {
            $this->error('Every --paths value must be a non-empty repository-relative path.');

            return self::FAILURE;
        }
        $validatedPaths = [];
        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                $this->error('Every --paths value must be a non-empty repository-relative path.');

                return self::FAILURE;
            }
            $validatedPaths[] = $path;
        }
        try {
            $impact = new DocsImpact($root);
            if ($this->option('gate')) {
                if (! is_string($base)) {
                    $this->error('The --gate option requires --base <commit>.');

                    return self::FAILURE;
                }
                $gate = $impact->gate($base);
                $this->line(json_encode($gate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                if ($gate['missing_pages'] !== []) {
                    $this->error('Impacted documentation pages were not changed: '.implode(', ', $gate['missing_pages']));
                }
                foreach ($gate['failures'] as $failure) {
                    $this->error($failure);
                }
                if (! $gate['passed']) {
                    return self::FAILURE;
                }

                return self::SUCCESS;
            }
            $report = $impact->report($base, $validatedPaths);
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
