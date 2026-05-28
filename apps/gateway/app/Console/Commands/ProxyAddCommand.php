<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Proxy\AddProxyRouteRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteMutationResponse;
use App\Services\Proxy\ProxyRouteIntent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('proxy:add
    {domain? : Proxy route domain}
    {--node= : Serving node}
    {--upstream= : HTTP or HTTPS upstream URL}
    {--redirect= : HTTP or HTTPS redirect URL}
    {--code= : Redirect status code}
    {--force : Replace an existing custom route with different intent}
    {--json : Output JSON}')]
#[Description('Create or update custom proxy route intent')]
class ProxyAddCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    public function handle(ProxyRouteIntent $intent): int
    {
        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        if (! $this->wantsJson()) {
            return $this->handleAddHuman($input, $intent);
        }

        try {
            if (! (bool) config('orbit.is_gateway', false)) {
                return $this->forwardAdd($input);
            }

            $result = $intent->add(
                domain: $input['domain'],
                nodeName: $input['node'],
                upstream: $input['upstream'],
                redirect: $input['redirect'],
                code: $input['code'],
                force: $input['force'],
            );
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to add proxy routes.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to add proxy routes.',
                meta: [],
            );
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    /**
     * @param  array{domain: string, node: string, upstream: ?string, redirect: ?string, code: ?int, force: bool}  $input
     */
    private function handleAddHuman(array $input, ProxyRouteIntent $intent): int
    {
        $result = null;
        $failure = null;
        $onGateway = null;

        $exitCode = $this->runStepTree(
            'Adding Proxy Route',
            [
                [
                    'label' => 'Validate proxy route',
                    'doneLabel' => 'Validated proxy route',
                    'run' => fn (): string => $input['domain'],
                ],
                [
                    'label' => 'Check ownership boundary',
                    'doneLabel' => 'Checked ownership boundary',
                    'run' => function () use (&$onGateway): string {
                        $onGateway = (bool) config('orbit.is_gateway', false);

                        return $onGateway ? 'local gateway' : 'gateway API';
                    },
                ],
                [
                    'label' => 'Apply and verify proxy route',
                    'doneLabel' => 'Applied and verified proxy route',
                    'run' => function () use ($input, $intent, &$result, &$failure, &$onGateway): string {
                        try {
                            if (! $onGateway) {
                                /** @var ProxyRouteMutationResponse $dto */
                                $dto = app(GatewayConnector::class)
                                    ->send(new AddProxyRouteRequest(
                                        domain: $input['domain'],
                                        node: $input['node'],
                                        upstream: $input['upstream'],
                                        redirect: $input['redirect'],
                                        code: $input['code'],
                                        force: $input['force'],
                                    ))
                                    ->dto();

                                $result = ['data' => $dto->data, 'meta' => $dto->meta];

                                return 'gateway accepted route intent';
                            }

                            $result = $intent->add(
                                domain: $input['domain'],
                                nodeName: $input['node'],
                                upstream: $input['upstream'],
                                redirect: $input['redirect'],
                                code: $input['code'],
                                force: $input['force'],
                            );

                            return 'route intent saved';
                        } catch (GatewayApiException $e) {
                            $failure = [
                                'code' => $e->errorCode() ?? 'gateway_unavailable',
                                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to add proxy routes.',
                                'meta' => $e->errorMeta(),
                            ];

                            return "fail:{$failure['message']}";
                        } catch (Throwable) {
                            $failure = [
                                'code' => 'gateway_unavailable',
                                'message' => 'Gateway connection is required to add proxy routes.',
                                'meta' => [],
                            ];

                            return "fail:{$failure['message']}";
                        }
                    },
                ],
                [
                    'label' => 'Apply and verify TLS material',
                    'doneLabel' => 'Applied and verified TLS material',
                    'run' => fn (): string => 'TLS enactment deferred to doctor',
                ],
            ],
            doneFooter: 'Proxy route intent saved',
            failFooter: 'Failed to save proxy route intent',
        );

        if ($exitCode !== self::SUCCESS || $result === null) {
            return is_array($failure)
                ? $this->failCommand($failure['code'], $failure['message'], $failure['meta'])
                : self::FAILURE;
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    /**
     * @param  array{domain: string, node: string, upstream: ?string, redirect: ?string, code: ?int, force: bool}  $input
     */
    private function forwardAdd(array $input): int
    {
        /** @var ProxyRouteMutationResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new AddProxyRouteRequest(
                domain: $input['domain'],
                node: $input['node'],
                upstream: $input['upstream'],
                redirect: $input['redirect'],
                code: $input['code'],
                force: $input['force'],
            ))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
    }

    /**
     * @return array{domain: string, node: string, upstream: ?string, redirect: ?string, code: ?int, force: bool}|int
     */
    private function validatedInput(): array|int
    {
        $domain = $this->stringArgument('domain');
        $node = $this->stringOption('node');
        $upstream = $this->stringOption('upstream');
        $redirect = $this->stringOption('redirect');
        $codeInput = $this->stringOption('code');

        $isInteractive = $this->isInteractiveInput();

        if ($domain === null) {
            if (! $isInteractive) {
                return $this->failValidation('domain', 'The proxy route domain is required.');
            }

            try {
                $domain = $this->promptText(label: 'Domain', required: true);
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }
        }

        if ($node === null) {
            if (! $isInteractive) {
                return $this->failCommand(
                    'node_target_required',
                    'A node target is required. Provide --node.',
                    ['field' => 'node'],
                );
            }

            try {
                $selected = $this->promptForVisibleNode(label: 'Select the serving node');

                if ($selected instanceof GatewayApiException) {
                    return $this->failCommand(
                        code: $selected->errorCode() ?? 'gateway_unavailable',
                        message: $selected->getMessage(),
                        meta: $selected->errorMeta(),
                    );
                }

                $node = $selected;
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }
        }

        if ($upstream !== null && $redirect !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Select exactly one of --upstream or --redirect.',
                meta: ['fields' => ['upstream', 'redirect']],
            );
        }

        if ($upstream === null && $redirect === null) {
            if (! $isInteractive) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Select exactly one of --upstream or --redirect.',
                    meta: ['fields' => ['upstream', 'redirect']],
                );
            }

            try {
                $routeShape = (string) $this->promptSelect(
                    label: 'Route type',
                    options: ['upstream' => 'Upstream', 'redirect' => 'Redirect'],
                    default: 'upstream',
                );

                if ($routeShape === 'upstream') {
                    $upstream = $this->promptText(label: 'Upstream URL', required: true);
                } else {
                    $redirect = $this->promptText(label: 'Redirect URL', required: true);
                }
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }
        }

        if ($upstream !== null && $codeInput !== null) {
            return $this->failValidation('code', '--code may only be used with --redirect.');
        }

        $code = $codeInput === null ? null : (int) $codeInput;

        if ($code !== null && ! in_array($code, [301, 302, 307, 308], true)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Invalid redirect code.',
                meta: [
                    'field' => 'code',
                    'allowed' => [301, 302, 307, 308],
                ],
            );
        }

        return [
            'domain' => $domain,
            'node' => $node,
            'upstream' => $upstream,
            'redirect' => $redirect,
            'code' => $code,
            'force' => $this->option('force') === true,
        ];
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
        $this->line("Proxy route '".(string) ($route['domain'] ?? '')."' saved on node '".(string) ($route['node'] ?? '')."'.");

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

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
