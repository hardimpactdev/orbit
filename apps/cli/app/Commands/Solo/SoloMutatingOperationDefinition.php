<?php

declare(strict_types=1);

namespace App\Commands\Solo;

/**
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class SoloMutatingOperationDefinition
{
    public function __construct(
        public string $command,
        public string $signature,
        public string $method,
        public string $gatewayPath,
        public string $successKey,
        /** @var list<string> */
        public array $requiredArguments = [],
        /** @var array<string, string> */
        public array $payloadOptions = [],
        public bool $forceRequired = false,
        public bool $destructiveConsent = false,
    ) {}
}
