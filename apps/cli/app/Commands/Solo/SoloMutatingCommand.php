<?php

declare(strict_types=1);

namespace App\Commands\Solo;

use App\Commands\Concerns\RequiresLocalExtension;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class SoloMutatingCommand extends GatewayCommand
{
    use RequiresLocalExtension;

    #[\Override]
    protected $signature;

    #[\Override]
    protected $description;

    public function __construct(
        private readonly SoloMutatingOperationDefinition $operation,
    ) {
        $this->signature = $operation->signature;
        $this->description = "Run {$operation->command} through the Solo gateway proxy.";

        parent::__construct();
    }

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        if ($this->operation->forceRequired && $this->option('force') !== true) {
            return $this->renderFailure('validation_failed', 'This Solo command requires --force.', [
                'reason' => 'force_required',
            ]);
        }

        /** @var array<string, mixed> $payload */
        $payload = [];

        foreach ($this->operation->requiredArguments as $argument) {
            $value = $this->stringArgumentValue($argument);

            if ($value === null) {
                return $this->renderFailure('validation_failed', "The {$argument} argument is required.", [
                    'field' => $argument,
                ]);
            }

            $payload[$this->payloadKeyForArgument($argument)] = $value;
        }

        foreach ($this->operation->payloadOptions as $option => $payloadKey) {
            $value = $this->option($option);

            if (is_scalar($value) && (string) $value !== '') {
                $payload[$payloadKey] = (string) $value;
            }
        }

        try {
            $response = $this->gatewayMutation($payload);
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function gatewayMutation(array $payload): array
    {
        return match ($this->operation->method) {
            'DELETE' => $this->gatewayDelete($this->operation->gatewayPath, $payload),
            'PATCH' => $this->gatewayPatch($this->operation->gatewayPath, $payload),
            'PUT' => $this->gatewayPut($this->operation->gatewayPath, $payload),
            default => $this->gatewayPost($this->operation->gatewayPath, $payload),
        };
    }

    private function stringArgumentValue(string $argument): ?string
    {
        $value = $this->argument($argument);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function payloadKeyForArgument(string $argument): string
    {
        return $argument === 'processCommand' ? 'command' : $argument;
    }
}
