<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

use Illuminate\Support\Facades\Process;
use Throwable;

final readonly class LocalCodexAppConfigMutation
{
    private const string APPLY_URL = 'codex://codex-app/apply-config';

    public function __construct(
        private LocalCodexAppConfigStore $store,
        private LocalCodexAppConfigMerger $merger,
    ) {}

    /**
     * @param  array{label: string, ssh_alias: string, remote_path?: string}  $project
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(string $path, string $mutation, array $project): array
    {
        $lock = $this->store->acquire($path);

        try {
            $config = $this->store->read($path);
            [$merged, $removed] = $this->merger->merge($config, $mutation, $project);
            $changed = $merged !== $config;

            if ($changed) {
                $contents = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
                $this->store->replace($path, $contents);
            }

            $data = [
                'path' => $path,
                'changed' => $changed,
            ];

            if ($mutation === 'remove') {
                $data['removed'] = $removed;
            }

            return [
                'data' => $data,
                'meta' => $this->applyMeta(),
            ];
        } finally {
            $this->store->release($lock, $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applyMeta(): array
    {
        try {
            $result = Process::timeout(15)->run(['open', self::APPLY_URL]);
        } catch (Throwable $exception) {
            return $this->applyWarning(null, $exception->getMessage());
        }

        if ($result->successful()) {
            return [];
        }

        $stderr = trim($result->errorOutput());
        $stdout = trim($result->output());

        return $this->applyWarning($result->exitCode(), $stderr !== '' ? $stderr : $stdout);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyWarning(?int $exitCode, string $stderr): array
    {
        return [
            'warnings' => [[
                'code' => 'codex_app.apply_failed',
                'message' => 'Codex App config apply callback failed.',
                'meta' => [
                    'exit_code' => $exitCode,
                    'stderr' => $stderr,
                ],
            ]],
        ];
    }
}
