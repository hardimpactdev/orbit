<?php

declare(strict_types=1);

namespace App\Commands\Solo;

use App\Commands\Concerns\RequiresLocalExtension;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

/** @mago-expect lint:cyclomatic-complexity */
final class SoloMutatingCommand extends GatewayCommand
{
    use RequiresLocalExtension;

    private const array ARGUMENT_PAYLOAD_KEYS = [
        'processCommand' => 'command',
    ];

    #[\Override]
    protected $signature;

    #[\Override]
    protected $description;

    public function __construct(
        private readonly SoloMutatingOperationDefinition $operation,
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

        if (
            $this->operation->forceRequired
            && ! $this->operation->destructiveConsent
            && $this->option('force') !== true
        ) {
            return $this->renderFailure('validation_failed', 'This Solo command requires --force.', [
                'reason' => 'force_required',
            ]);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = $this->targetPayload->forNode($this->stringOptionValue('node'));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

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

        $confirmation = $this->confirmDestructiveMutation($payload);

        if ($confirmation !== null) {
            return $confirmation;
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

    private function stringOptionValue(string $option): ?string
    {
        $value = $this->option($option);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function payloadKeyForArgument(string $argument): string
    {
        return self::ARGUMENT_PAYLOAD_KEYS[$argument] ?? $argument;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirmDestructiveMutation(array $payload): ?int
    {
        if (! $this->operation->destructiveConsent || $this->option('force') === true) {
            return null;
        }

        $meta = [
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ];

        if (! $this->allowsInteractiveInput()) {
            return $this->renderFailure(
                'validation_failed',
                'Use --force to run this destructive Solo command.',
                $meta,
            );
        }

        if ($this->confirm($this->destructiveConfirmationLabel($payload), default: false)) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', $meta);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function destructiveConfirmationLabel(array $payload): string
    {
        $argument = $this->operation->requiredArguments[0] ?? null;

        if ($argument === null) {
            return "Run destructive Solo command '{$this->operation->command}'?";
        }

        $value = $payload[$this->payloadKeyForArgument($argument)] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            return "Run destructive Solo command '{$this->operation->command}'?";
        }

        return "Run destructive Solo command '{$this->operation->command}' for {$argument} '{$value}'?";
    }
}
