<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use App\Services\Analytics\AnalyticsReadinessVerifier;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final class AppAnalyticsVerifyCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $name = 'instance:analytics verify';

    #[\Override]
    protected $description = 'Verify public analytics tracking readiness for an instance.';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('instance', InputArgument::OPTIONAL, 'Instance selector (app.instance or hostname)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(AnalyticsReadinessVerifier $verifier): int
    {
        $selector = $this->stringArgument('instance');

        if ($selector === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        if ($this->wantsJson()) {
            return $this->verifyJson($selector, $verifier);
        }

        return $this->verifyHuman($selector, $verifier);
    }

    private function verifyJson(string $selector, AnalyticsReadinessVerifier $verifier): int
    {
        try {
            $verification = $verifier->verify($this->verificationContext($selector));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if (($verification['ready'] ?? false) !== true) {
            return $this->renderFailure(
                'analytics.public_not_ready',
                'Instance analytics public readiness is incomplete.',
                ['instance' => $selector],
                ['verification' => $verification],
            );
        }

        return $this->renderSuccess(['verification' => $verification]);
    }

    private function verifyHuman(string $selector, AnalyticsReadinessVerifier $verifier): int
    {
        $verification = [];
        $outcome = $this->runStepOperation(
            'Verifying Instance Analytics',
            [
                ['label' => 'Read analytics binding and route intent'],
                ['label' => 'Resolve public DNS'],
                ['label' => 'Verify TLS and tracking script'],
                ['label' => 'Verify dashboard remains private'],
            ],
            work: function () use ($selector, $verifier, &$verification): array {
                try {
                    return $verification = $verifier->verify($this->verificationContext($selector));
                } catch (GatewayApiException $exception) {
                    throw new RuntimeException(
                        $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                        previous: $exception,
                    );
                }
            },
            doneFooter: 'Analytics verification completed',
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderVerification($verification);

        if (($verification['ready'] ?? false) !== true) {
            $this->line(
                'No state was changed. Configure or repair the reported public readiness layer, then verify again.',
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<array-key, mixed> */
    private function verificationContext(string $selector): array
    {
        $response = $this->gatewayGet($this->apiInstancePath($selector, '/analytics/verify'));
        $success = $response['success'] ?? null;
        $data = is_array($success) ? $success['data'] ?? null : null;
        $payload = is_array($data) ? $data : $response;
        $context = $payload['verification_context'] ?? null;

        return is_array($context) ? $context : [];
    }

    /** @param array<array-key, mixed> $verification */
    private function renderVerification(array $verification): void
    {
        $this->line('verification:');
        $this->line('  instance: '.$this->stringField($verification, 'instance'));
        $this->line('  ready: '.(($verification['ready'] ?? false) === true ? 'true' : 'false'));
        $this->line('  hosts:');

        foreach ($this->arrayList($verification['hosts'] ?? []) as $host) {
            $this->renderHost($host);
        }
    }

    /** @param array<array-key, mixed> $host */
    private function renderHost(array $host): void
    {
        $route = $this->arrayField($host, 'route_intent');
        $dns = $this->arrayField($host, 'dns');
        $tls = $this->arrayField($host, 'tls');
        $script = $this->arrayField($host, 'script');
        $dashboard = $this->arrayField($host, 'dashboard');
        $event = $this->arrayField($host, 'event');
        $site = $this->arrayField($host, 'plausible_site');

        $this->line('    - host: '.$this->stringField($host, 'host'));
        $this->line('      route_intent: '.$this->stringField($route, 'status'));
        $this->line('      dns: '.$this->stringField($dns, 'status'));
        $this->line('      tls: '.$this->stringField($tls, 'status'));
        $this->line('      script: '.$this->statusWithCode($script));
        $this->line('      dashboard: '.$this->statusWithCode($dashboard));
        $this->line('      event: '.$this->stringField($event, 'status'));
        $this->line('      plausible_site: '.$this->stringField($site, 'status'));
        $this->line('      ready: '.(($host['ready'] ?? false) === true ? 'true' : 'false'));
    }

    /** @param array<array-key, mixed> $value */
    private function statusWithCode(array $value): string
    {
        $status = $this->stringField($value, 'status');
        $code = $value['http_status'] ?? null;

        return is_int($code) ? "{$status} ({$code})" : $status;
    }

    /** @return list<array<array-key, mixed>> */
    private function arrayList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /** @param array<array-key, mixed> $array */
    private function arrayField(array $array, string $key): array
    {
        $value = $array[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @param array<array-key, mixed> $array */
    private function stringField(array $array, string $key): string
    {
        $value = $array[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
