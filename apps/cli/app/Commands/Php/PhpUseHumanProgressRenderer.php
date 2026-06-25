<?php

declare(strict_types=1);

namespace App\Commands\Php;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class PhpUseHumanProgressRenderer
{
    public function __construct(
        private OutputInterface $output,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $gatewayUse
     */
    public function render(array $payload, ?string $version, callable $gatewayUse): int
    {
        if (($payload['cli'] ?? false) === true) {
            return new PhpUseCliHumanTreeRunner($this->output)->run($payload, $version, $gatewayUse);
        }

        $inherit = ($payload['inherit'] ?? false) === true;

        return new PhpUseGatewayHumanTreeRunner($this->output)->run($payload, $version, $gatewayUse, $inherit);
    }
}
