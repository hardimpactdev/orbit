<?php

namespace App\Concerns;

trait WithJsonOutput
{
    protected function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    protected function outputJson(array $data, int $exitCode = self::SUCCESS): int
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $exitCode;
    }

    protected function outputJsonSuccess(array $data): int
    {
        return $this->outputJson([
            'success' => true,
            'data' => $data,
        ], self::SUCCESS);
    }

    protected function outputJsonError(string $message, int $exitCode = self::FAILURE, array $extra = []): int
    {
        return $this->outputJson(array_merge([
            'success' => false,
            'error' => $message,
        ], $extra), $exitCode);
    }
}
