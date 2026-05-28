<?php

declare(strict_types=1);

namespace App\Commands\Php;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class PhpUseCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /** @var list<string> */
    private const array SUPPORTED = ['8.5', '8.4', '8.3'];

    private const string DEFAULT = '8.5';

    #[\Override]
    protected $signature = 'php:use
        {version? : PHP version to select}
        {--app= : App selector}
        {--workspace= : Workspace selector}
        {--node= : Node selector}
        {--inherit : Restore workspace PHP inheritance}
        {--cli : Select node CLI PHP default}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Select PHP runtime intent for an app, workspace, or node CLI default.';

    public function handle(): int
    {
        $optionFailure = $this->validateOptionCombination();

        if ($optionFailure !== null) {
            return $optionFailure;
        }

        $version = $this->resolveVersion();

        if (is_int($version)) {
            return $version;
        }

        try {
            $response = $this->gatewayPost('/api/php/use', $this->payload($version));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function validateOptionCombination(): ?int
    {
        if ($this->option('inherit') === true && $this->stringArgument('version') !== null) {
            return $this->renderFailure(
                'validation_failed',
                'Cannot provide both a PHP version and --inherit.',
                ['fields' => ['version', 'inherit'], 'reason' => 'mutually_exclusive_input'],
            );
        }

        if ($this->option('cli') !== true) {
            return null;
        }

        if ($this->stringOption('app') === null && $this->stringOption('workspace') === null && $this->option('inherit') !== true) {
            return null;
        }

        return $this->renderFailure(
            'validation_failed',
            'CLI PHP selection cannot be combined with app, workspace, or inheritance targets.',
            ['fields' => ['cli', 'app', 'workspace', 'inherit'], 'reason' => 'mutually_exclusive_input'],
        );
    }

    private function resolveVersion(): string|int|null
    {
        if ($this->option('inherit') === true) {
            return null;
        }

        $version = $this->stringArgument('version');

        if ($version !== null) {
            return $version;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'A PHP version is required.', [
                'field' => 'version',
                'reason' => 'missing',
            ]);
        }

        $answer = $this->choice('PHP version', self::SUPPORTED, self::DEFAULT);

        return is_string($answer) && trim($answer) !== ''
            ? trim($answer)
            : $this->renderFailure('validation_failed', 'A PHP version is required.', [
                'field' => 'version',
                'reason' => 'missing',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $version): array
    {
        $cli = $this->option('cli') === true;
        $node = $this->stringOption('node');

        if ($cli && $node === null) {
            $node = $this->targetNodeOptionOrDefault();
        }

        return array_filter([
            'version' => $version,
            'app' => $cli ? null : ($this->stringOption('app') ?? $this->appFromOrbitMarker()),
            'workspace' => $cli ? null : $this->stringOption('workspace'),
            'node' => $node,
            'inherit' => $this->option('inherit') === true,
            'cli' => $cli,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
