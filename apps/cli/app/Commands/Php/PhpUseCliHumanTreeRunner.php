<?php

declare(strict_types=1);

namespace App\Commands\Php;

use Orbit\Core\Progress\StepTree;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class PhpUseCliHumanTreeRunner
{
    private const string DEFAULT_VERSION = '8.5';

    public function __construct(
        private OutputInterface $output,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $gatewayUse
     */
    public function run(array $payload, ?string $version, callable $gatewayUse): int
    {
        $node = is_string($payload['node'] ?? null) ? $payload['node'] : null;
        $nodeLabel = $node ?? 'node';
        $versionLabel = $version ?? self::DEFAULT_VERSION;
        $response = [];

        $result = new StepTree($this->output)->run(
            "Updating node CLI PHP on {$nodeLabel} to PHP {$versionLabel}",
            [
                [
                    'label' => 'Resolve target',
                    'doneLabel' => "Resolved target {$nodeLabel}",
                    'run' => static fn (): null => null,
                ],
                [
                    'label' => 'Validate host PHP CLI version',
                    'doneLabel' => 'Validated host PHP CLI version',
                    'run' => fn (): null => $this->storeGatewayUseResponse($payload, $gatewayUse, $response),
                ],
                [
                    'label' => 'Apply host PHP CLI default',
                    'doneLabel' => 'Applied host PHP CLI default',
                    'run' => static fn (): null => null,
                ],
            ],
            static fn (): string => "Successfully updated node CLI PHP on {$nodeLabel} to PHP {$versionLabel}",
        );

        if (! $result->isCompleted()) {
            return 1;
        }

        PhpUseHumanDriftNotes::render($this->output, $response);

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $gatewayUse
     * @param  array<string, mixed>  $response
     */
    private function storeGatewayUseResponse(array $payload, callable $gatewayUse, array &$response): null
    {
        $response = PhpUseHumanGatewayCaller::call(
            static fn (): array => $gatewayUse($payload),
        );

        return null;
    }
}
