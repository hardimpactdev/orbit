<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

use function Laravel\Prompts\confirm;

final class ProxyRemoveCommand extends ProxyGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'proxy:remove
        {domain? : Existing custom proxy route domain}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove custom proxy route intent.';

    public function handle(): int
    {
        $domain = $this->stringArgument('domain');

        if ($domain === null) {
            return $this->failValidation('domain', 'The proxy route domain is required.');
        }

        $confirmation = $this->confirmRemoval($domain);

        if ($confirmation !== null) {
            return $confirmation;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeRoute($domain);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemoveTree($domain);
    }

    private function renderRemoveTree(string $domain): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Proxy Route',
            [
                ['label' => 'Confirm destructive removal'],
                ['label' => 'Resolve proxy route'],
                ['label' => 'Remove backend proxy route'],
                ['label' => 'Remove route-scoped TLS material'],
                ['label' => 'Apply and verify proxy removal'],
            ],
            work: function () use ($domain, &$response): array {
                return $response = $this->removeRouteForHuman($domain);
            },
            doneFooter: "Proxy route '{$domain}' removed",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRemovalDetail($response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRoute(string $domain): array
    {
        return $this->gatewayDelete('/api/proxy-routes/'.rawurlencode($domain), [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);
    }

    /**
     * Run the destructive call inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function removeRouteForHuman(string $domain): array
    {
        try {
            return $this->removeRoute($domain);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Render the documented removal detail block: removed domain, kind, serving
     * node, target, and whether backend and TLS cleanup completed or were
     * skipped.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderRemovalDetail(array $response): void
    {
        $route = $this->routeData($response);

        if ($route !== []) {
            $this->line('  Domain: '.$this->stringField($route, 'domain'));
            $this->line('  Kind: '.$this->stringField($route, 'kind'));
            $this->line('  Serving node: '.$this->stringField($route, 'node'));
            $this->line('  Target: '.$this->targetSummary($route));
        }

        $meta = $this->metaData($response);

        $this->line('  Backend cleanup: '.$this->cleanupSummary($meta['backend_removed'] ?? null));
        $this->line('  TLS cleanup: '.$this->cleanupSummary($meta['tls_removed'] ?? null));
    }

    private function cleanupSummary(mixed $value): string
    {
        return $value === true ? 'completed' : 'skipped';
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function targetSummary(array $route): string
    {
        $target = $route['target'] ?? null;

        if (! is_array($target)) {
            return '-';
        }

        $type = is_string($target['type'] ?? null) ? $target['type'] : '-';
        $value = is_string($target['value'] ?? null) ? $target['value'] : '-';

        return "{$type} {$value}";
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function stringField(array $route, string $key): string
    {
        return is_string($route[$key] ?? null) && $route[$key] !== '' ? $route[$key] : '-';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function routeData(array $response): array
    {
        $data = $response['success']['data'] ?? null;
        $route = is_array($data) ? ($data['route'] ?? null) : null;

        return is_array($route) ? $route : [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function metaData(array $response): array
    {
        $meta = $response['success']['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    private function confirmRemoval(string $domain): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'destructive_consent_required',
                'Use --force to remove this proxy route.',
                ['field' => 'force'],
            );
        }

        if (confirm(label: "Remove proxy route '{$domain}'?", default: false)) {
            return null;
        }

        return $this->renderFailure(
            'destructive_consent_required',
            'Operation cancelled.',
            ['field' => 'force'],
        );
    }
}
