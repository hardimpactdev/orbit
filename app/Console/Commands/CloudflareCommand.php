<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Http\Gateway\Responses\Cloudflare\CloudflareResponse;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

abstract class CloudflareCommand extends Command
{
    /**
     * @param  callable(): array{data: array<string, mixed>, meta: array<string, mixed>}  $local
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}|int
     */
    protected function resolveCloudflareResult(
        CallerRoleResolver $callerRoleResolver,
        CloudflareRequest $gatewayRequest,
        callable $local,
        string $gatewayFailureMessage,
    ): array|int {
        $callerRole = $callerRoleResolver->resolve();

        if (in_array($callerRole, ['app', 'unknown'], true)) {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        try {
            if ($callerRole === 'gateway') {
                return $local();
            }

            /** @var CloudflareResponse $dto */
            $dto = app(GatewayConnector::class)->send($gatewayRequest)->dto();

            return ['data' => $dto->data, 'meta' => $dto->meta];
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                code: $exception->errorCode() ?? 'gateway_unavailable',
                message: $exception->getMessage() !== '' ? $exception->getMessage() : $gatewayFailureMessage,
                meta: $exception->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: $gatewayFailureMessage,
                meta: [],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  callable(array<string, mixed>, array<string, mixed>): void  $renderHuman
     */
    protected function successPayload(array $data, array $meta, callable $renderHuman): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $renderHuman($data, $meta);

        return self::SUCCESS;
    }

    protected function confirmDestructive(string $message): ?int
    {
        if ($this->input->hasOption('force') && $this->input->getOption('force') === true) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand(
                code: 'validation_failed',
                message: $message,
                meta: ['field' => 'force', 'reason' => 'destructive_consent_required'],
            );
        }

        if (confirm(label: $message, default: false)) {
            return null;
        }

        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: ['field' => 'force', 'reason' => 'destructive_consent_required'],
        );
    }

    protected function failValidation(string $field, string $message): int
    {
        return $this->failCommand('validation_failed', $message, ['field' => $field]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function failCommand(string $code, string $message, array $meta): int
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

    protected function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
