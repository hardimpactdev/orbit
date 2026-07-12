<?php

declare(strict_types=1);

namespace App\Commands\Solo;

use App\Commands\Concerns\RequiresLocalExtension;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class SoloReadOnlyCommand extends GatewayCommand
{
    use RequiresLocalExtension;

    #[\Override]
    protected $signature;

    #[\Override]
    protected $description;

    public function __construct(
        private readonly SoloReadOperationDefinition $operation,
        private readonly SoloCommandSignature $soloCommandSignature,
        private readonly SoloCommandTargetPayload $targetPayload,
    ) {
        $this->signature = $this->soloCommandSignature->withNodeOption($operation->signature);
        $this->description = "Run {$operation->command} through the Solo gateway proxy.";

        parent::__construct();
    }

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        try {
            $query = $this->targetPayload->forNode($this->stringOptionValue('node'));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        foreach ($this->operation->requiredArguments as $argument) {
            $value = $this->stringArgumentValue($argument);

            if ($value === null) {
                return $this->renderFailure(
                    'validation_failed',
                    "The {$argument} argument is required.",
                    ['field' => $argument],
                );
            }

            $query[$argument] = $value;
        }

        foreach ($this->operation->queryOptions as $option => $queryKey) {
            $value = $this->option($option);

            if (is_scalar($value) && (string) $value !== '') {
                $query[$queryKey] = (string) $value;
            }
        }

        try {
            $response = $this->gatewayGet($this->operation->gatewayPath, $query);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        app(SoloReadOnlyHumanRenderer::class)->render($this, $response);

        return self::SUCCESS;
    }

    protected function extensionSlug(): string
    {
        return 'solo';
    }

    private function stringArgumentValue(string $argument): ?string
    {
        $value = $this->argument($argument);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function stringOptionValue(string $option): ?string
    {
        $value = $this->option($option);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
