<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Proxy\RemoveProxyRouteRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteMutationResponse;
use App\Services\Proxy\ProxyRouteIntent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

#[Signature('proxy:remove
    {domain? : Existing custom proxy route domain}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove custom proxy route intent')]
class ProxyRemoveCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(ProxyRouteIntent $intent): int
    {
        $domain = $this->stringArgument('domain');

        if ($domain === null) {
            return $this->failValidation('domain', 'The proxy route domain is required.');
        }

        $consent = $this->confirmRemoval($domain);

        if (is_int($consent)) {
            return $consent;
        }

        if (! $this->wantsJson()) {
            return $this->handleRemoveHuman($domain, $intent);
        }

        try {
            if (! (bool) config('orbit.is_gateway', false)) {
                return $this->forwardRemove($domain);
            }

            $result = $intent->remove($domain);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to remove proxy routes.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove proxy routes.',
                meta: [],
            );
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    private function handleRemoveHuman(string $domain, ProxyRouteIntent $intent): int
    {
        $result = null;
        $failure = null;
        $onGateway = null;

        $exitCode = $this->runStepTree(
            'Removing Proxy Route',
            [
                [
                    'label' => 'Confirm destructive removal',
                    'doneLabel' => 'Confirmed destructive removal',
                    'run' => fn (): string => $domain,
                ],
                [
                    'label' => 'Resolve proxy route',
                    'doneLabel' => 'Resolved proxy route',
                    'run' => function () use (&$onGateway): string {
                        $onGateway = (bool) config('orbit.is_gateway', false);

                        return $onGateway ? 'local gateway' : 'gateway API';
                    },
                ],
                [
                    'label' => 'Remove backend proxy route',
                    'doneLabel' => 'Removed backend proxy route',
                    'run' => function () use ($domain, $intent, &$result, &$failure, &$onGateway): string {
                        try {
                            if (! $onGateway) {
                                /** @var ProxyRouteMutationResponse $dto */
                                $dto = app(GatewayConnector::class)
                                    ->send(new RemoveProxyRouteRequest(domain: $domain))
                                    ->dto();

                                $result = ['data' => $dto->data, 'meta' => $dto->meta];

                                return 'gateway removed route intent';
                            }

                            $result = $intent->remove($domain);

                            return 'route intent removed';
                        } catch (GatewayApiException $e) {
                            $failure = [
                                'code' => $e->errorCode() ?? 'gateway_unavailable',
                                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to remove proxy routes.',
                                'meta' => $e->errorMeta(),
                            ];

                            return "fail:{$failure['message']}";
                        } catch (Throwable) {
                            $failure = [
                                'code' => 'gateway_unavailable',
                                'message' => 'Gateway connection is required to remove proxy routes.',
                                'meta' => [],
                            ];

                            return "fail:{$failure['message']}";
                        }
                    },
                ],
                [
                    'label' => 'Remove route-scoped TLS material',
                    'doneLabel' => 'Removed route-scoped TLS material',
                    'run' => fn (): string => 'TLS cleanup deferred to doctor',
                ],
                [
                    'label' => 'Apply and verify proxy removal',
                    'doneLabel' => 'Applied and verified proxy removal',
                    'run' => fn (): string => 'removal accepted',
                ],
            ],
            doneFooter: 'Proxy route intent removed',
            failFooter: 'Failed to remove proxy route intent',
        );

        if ($exitCode !== self::SUCCESS || $result === null) {
            return is_array($failure)
                ? $this->failCommand($failure['code'], $failure['message'], $failure['meta'])
                : self::FAILURE;
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    private function forwardRemove(string $domain): int
    {
        /** @var ProxyRouteMutationResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RemoveProxyRouteRequest(domain: $domain))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
    }

    private function confirmRemoval(string $domain): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand(
                code: 'destructive_consent_required',
                message: 'Use --force to remove this proxy route.',
                meta: ['field' => 'force'],
            );
        }

        if (confirm(label: "Remove proxy route '{$domain}'?", default: false)) {
            return null;
        }

        return $this->failCommand(
            code: 'destructive_consent_required',
            message: 'Operation cancelled.',
            meta: ['field' => 'force'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function successPayload(array $data, array $meta): int
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

        $route = is_array($data['route'] ?? null) ? $data['route'] : [];
        $this->line("Proxy route '".(string) ($route['domain'] ?? '')."' removed.");

        $this->renderWarnings($meta);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function renderWarnings(array $meta): void
    {
        $warnings = is_array($meta['warnings'] ?? null) ? $meta['warnings'] : [];

        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $this->line('  Drift detected: '.(string) ($warning['code'] ?? 'warning'));
            }
        }
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand('validation_failed', $message, ['field' => $field]);
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

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
