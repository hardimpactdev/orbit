<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\Instance;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class FrankenPhpRuntimeConfigRenderer
{
    private const array AppDevelopmentThreadPoolLines = [
        'max_threads auto',
        'max_idle_time 1h',
    ];

    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function classic(App $app, ?Instance $instance = null): ?string
    {
        $lines = $this->threadPoolLines($app, $instance);

        if ($lines === []) {
            return null;
        }

        return $this->render($lines);
    }

    public function worker(App $app, string $workerFile, string|int $workers, ?Instance $instance = null): string
    {
        return $this->render([
            ...$this->threadPoolLines($app, $instance),
            ...$this->workerLines($workerFile, $workers),
        ]);
    }

    /**
     * @return list<string>
     */
    private function threadPoolLines(App $app, ?Instance $instance): array
    {
        if ($this->placement->runtimeNode($app, $instance)?->hasActiveRole('app-dev') !== true) {
            return [];
        }

        return self::AppDevelopmentThreadPoolLines;
    }

    /**
     * @return list<string>
     */
    private function workerLines(string $workerFile, string|int $workers): array
    {
        $lines = [
            'worker {',
            "\tfile {$workerFile}",
        ];

        if (is_int($workers) && $workers > 0) {
            $lines[] = "\tnum {$workers}";
        }

        $lines[] = '}';

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function render(array $lines): string
    {
        return implode("\n", $lines);
    }
}
