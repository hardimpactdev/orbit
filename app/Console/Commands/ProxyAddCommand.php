<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Proxy\AddProxyRouteRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteMutationResponse;
use App\Models\LocalNodeDefault;
use App\Services\Nodes\CallerRoleResolver;
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
    public function handle(ProxyRouteIntent $intent, CallerRoleResolver $callerRoleResolver): int
    {
        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        try {
            if ($callerRoleResolver->resolve() !== 'gateway') {
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
        $node = $this->stringOption('node') ?? $this->defaultNodeName();
        $upstream = $this->stringOption('upstream');
        $redirect = $this->stringOption('redirect');
        $codeInput = $this->stringOption('code');

        if ($domain === null) {
            return $this->failValidation('domain', 'The proxy route domain is required.');
        }

        if ($node === null) {
            return $this->failValidation('node', 'A serving node is required.');
        }

        if (($upstream === null && $redirect === null) || ($upstream !== null && $redirect !== null)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Select exactly one of --upstream or --redirect.',
                meta: ['fields' => ['upstream', 'redirect']],
            );
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
        $this->line('┌ Adding Proxy Route');
        $this->line('○ Validate proxy route');
        $this->line('○ Check ownership boundary');
        $this->line('○ Apply and verify proxy route');
        $this->line('○ Apply and verify TLS material');
        $this->line('└ Proxy route intent saved');
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

    private function defaultNodeName(): ?string
    {
        $name = LocalNodeDefault::query()->value('default_node_name');

        return is_string($name) && $name !== '' ? $name : null;
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

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
