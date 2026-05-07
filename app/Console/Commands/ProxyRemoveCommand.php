<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Proxy\RemoveProxyRouteRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteMutationResponse;
use App\Services\Nodes\CallerRoleResolver;
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
    public function handle(ProxyRouteIntent $intent, CallerRoleResolver $callerRoleResolver): int
    {
        $domain = $this->stringArgument('domain');

        if ($domain === null) {
            return $this->failValidation('domain', 'The proxy route domain is required.');
        }

        $consent = $this->confirmRemoval($domain);

        if (is_int($consent)) {
            return $consent;
        }

        try {
            if ($callerRoleResolver->resolve() !== 'gateway') {
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
        $this->line('┌ Removing Proxy Route');
        $this->line('○ Confirm destructive removal');
        $this->line('○ Resolve proxy route');
        $this->line('○ Remove backend proxy route');
        $this->line('○ Remove route-scoped TLS material');
        $this->line('○ Apply and verify proxy removal');
        $this->line('└ Proxy route intent removed');
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
