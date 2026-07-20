<?php

declare(strict_types=1);

namespace App\Commands\Php;

use Orbit\Core\Progress\StepTree;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class PhpUseGatewayHumanTreeRunner
{
    public function __construct(
        private OutputInterface $output,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $gatewayUse
     */
    public function run(
        array $payload,
        ?string $version,
        callable $gatewayUse,
        bool $inherit,
    ): int {
        $title = $inherit
            ? 'Restoring workspace PHP inheritance'
            : "Updating PHP runtime to PHP {$version}";
        $doneFooter = $inherit
            ? 'Successfully restored workspace PHP inheritance'
            : "Successfully updated PHP runtime to PHP {$version}";

        $node = is_string($payload['node'] ?? null) ? $payload['node'] : null;
        $instance = is_string($payload['instance'] ?? null) ? $payload['instance'] : null;
        $workspace = is_string($payload['workspace'] ?? null) ? $payload['workspace'] : null;
        $response = [];

        $result = new StepTree($this->output)->run(
            $title,
            [
                [
                    'label' => 'Resolve target',
                    'doneLabel' => 'Resolved target',
                    'run' => fn (): string => $this->targetLabel($node, $instance, $workspace),
                ],
                [
                    'label' => 'Validate version',
                    'doneLabel' => 'Validated version',
                    'run' => static fn (): null => null,
                ],
                [
                    'label' => 'Apply and verify PHP change',
                    'doneLabel' => 'Applied and verified PHP change',
                    'run' => fn (): null => $this->storeGatewayUseResponse($payload, $gatewayUse, $response),
                ],
            ],
            $doneFooter,
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

    private function targetLabel(?string $node, ?string $instance, ?string $workspace): string
    {
        if ($workspace !== null) {
            return $workspace;
        }

        if ($instance !== null) {
            return $instance;
        }

        return $node ?? 'target';
    }
}
