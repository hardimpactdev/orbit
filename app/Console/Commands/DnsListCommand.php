<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Services\Dns\LocalResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\table;

#[Signature('dns:list
    {--json : Output as JSON}')]
#[Description('List caller-local Orbit DNS resolver overrides')]
class DnsListCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    private ?int $activityCount = null;

    private ?string $activityResolverBackend = null;

    public function handle(LocalResolver $resolver): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeDnsList($resolver);
        } finally {
            $this->finishActivityLog();
        }
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'count' => $this->activityCount,
            'resolver_backend' => $this->activityResolverBackend,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        return 'Local DNS resolver overrides listed';
    }

    private function executeDnsList(LocalResolver $resolver): int
    {
        if ((bool) config('orbit.is_gateway', false)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'This command is not supported on gateway nodes.',
                meta: ['reason' => 'not_supported_on_gateway'],
            );
        }

        if (! $resolver->isSupported()) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic local DNS resolver inspection.',
                meta: ['platform' => $resolver->platform()],
            );
        }

        try {
            $dns = $resolver->listOverrides();
        } catch (RuntimeException) {
            return $this->failCommand(
                code: 'local_resolver_read_failed',
                message: 'Could not read local DNS resolver configuration.',
                meta: ['resolver_backend' => $resolver->backend()],
            );
        }

        $meta = [
            'count' => count($dns),
            'resolver_backend' => $resolver->backend(),
        ];
        $this->activityCount = $meta['count'];
        $this->activityResolverBackend = $meta['resolver_backend'];

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['dns' => $dns], $meta);
        }

        $this->renderHuman($dns);

        return self::SUCCESS;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented dns:list result.
        }
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<int, array<string, string>>  $dns
     */
    private function renderHuman(array $dns): void
    {
        if ($dns === []) {
            $this->line('No local Orbit DNS overrides found.');

            return;
        }

        table(
            headers: ['TLD', 'TARGET', 'SOURCE', 'BACKEND', 'STATUS'],
            rows: array_map(fn (array $entry): array => [
                $entry['tld'],
                $entry['target'],
                $entry['source'],
                $entry['resolver_backend'],
                $entry['status'],
            ], $dns),
        );
    }
}
