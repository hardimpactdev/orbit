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
    protected $signature = 'orbit:docs-impact {--base=} {--paths=*} {--gate} {--pr-body-file=}';

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
        $prBody = '';
        $bodyFile = $this->input->getOption('pr-body-file');
        if ($bodyFile === null && $this->option('gate')) {
            $bodyFile = getenv('ORBIT_DOCS_PR_BODY_FILE') ?: null;
        }
        if ($bodyFile !== null) {
            if (! $this->option('gate') || ! is_string($bodyFile) || ! is_file($bodyFile) || ! is_readable($bodyFile)) {
                $this->error('The --pr-body-file option requires --gate and a readable PR body file.');

                return self::FAILURE;
            }
            $prBody = file_get_contents($bodyFile);
            if ($prBody === false) {
                $this->error('Unable to read the PR body file.');

                return self::FAILURE;
            }
        }
        try {
            $impact = new DocsImpact($root);
            if ($this->option('gate')) {
                if (! is_string($base)) {
                    $this->error('The --gate option requires --base <commit>.');

                    return self::FAILURE;
                }
                $gate = $impact->gate($base, $prBody);
                $this->line(json_encode($gate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                if ($gate['missing_pages'] !== []) {
                    $this->error('Impacted documentation pages were not changed: '.implode(', ', $gate['missing_pages']));
                    $this->line('Update each page, or add a separate line to the PR body with its exact path and a specific reason:');
                    foreach ($gate['missing_pages'] as $page) {
                        $this->line('Docs-unaffected: '.$page.': <explain why no docs change is needed>');
                    }
                    $this->line('Check before pushing: bin/docs-impact --gate --base origin/main --pr-body-file /path/to/pr-body.md');
                    $runId = getenv('GITHUB_RUN_ID');
                    $run = is_string($runId) && ctype_digit($runId) ? $runId : '<run-id>';
                    $this->line('After editing the PR body, rerun failed jobs: gh run rerun '.$run.' --failed');
                    $this->line('The Docs job reads the live PR body. Successful jobs are retained; Required checks is re-evaluated.');
                    $this->line('Private Git notes do not waive this gate.');
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
