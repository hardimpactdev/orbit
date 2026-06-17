<?php

declare(strict_types=1);

namespace App\Commands\Php;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

use function Laravel\Prompts\table;

final class PhpListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'php:list
        {--app= : App selector}
        {--workspace= : Workspace selector}
        {--node= : Node selector}
        {--live : Inspect available PHP images live}
        {--json}';

    #[\Override]
    protected $description = 'List PHP runtime support and selected runtime intent.';

    public function handle(): int
    {
        try {
            $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();
            $workspace = $this->stringOption('workspace');
            $node = $this->stringOption('node');

            if ($node === null && $app === null && $workspace === null) {
                $node = $this->targetNodeOptionOrDefault();
            }

            $response = $this->gatewayGet('/api/php/runtime', $this->filledQuery([
                'app' => $app,
                'workspace' => $workspace,
                'node' => $node,
                'live' => $this->option('live') === true ? '1' : null,
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $runtime = $this->runtimeFromGatewayResponse($response);

        table(
            headers: ['ATTRIBUTE', 'VALUE'],
            rows: [
                ['NODE', $this->runtimeString($runtime, 'node')],
                ['SUPPORTED', $this->versionList($runtime, 'supported')],
                ['AVAILABLE IMAGES', $this->versionList($runtime, 'available_images')],
                ['CLI', $this->runtimeString($runtime, 'cli')],
                ['APP', $this->appLabel($runtime)],
                ['WORKSPACE', $this->workspaceLabel($runtime)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function runtimeFromGatewayResponse(array $response): array
    {
        $runtime = $response['success']['data']['php'] ?? null;

        return is_array($runtime) ? $runtime : [];
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function appLabel(array $runtime): string
    {
        $app = $runtime['app'] ?? null;

        if (! is_array($app)) {
            return '—';
        }

        $name = $this->runtimeString($app, 'name');
        $version = $this->runtimeString($app, 'php_version');

        if ($name === '—') {
            return '—';
        }

        return $version === '—' ? $name : "{$name} (PHP {$version})";
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function workspaceLabel(array $runtime): string
    {
        $workspace = $runtime['workspace'] ?? null;

        if (! is_array($workspace)) {
            return '—';
        }

        $name = $this->runtimeString($workspace, 'name');

        if ($name === '—') {
            return '—';
        }

        $version = $this->runtimeString($workspace, 'php_version');
        $inheritance = ($workspace['inherits'] ?? null) === true ? 'inherited' : 'pinned';

        return $version === '—'
            ? "{$name} ({$inheritance})"
            : "{$name} (PHP {$version}, {$inheritance})";
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function versionList(array $runtime, string $key): string
    {
        $versions = $runtime[$key] ?? null;

        if (! is_array($versions)) {
            return '—';
        }

        $labels = array_values(array_filter(
            array_map(
                fn (mixed $version): ?string => is_scalar($version) && (string) $version !== '' ? (string) $version : null,
                $versions,
            ),
            fn (?string $version): bool => $version !== null,
        ));

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function runtimeString(array $runtime, string $key): string
    {
        $value = $runtime[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
