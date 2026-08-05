<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class AppRootCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'instance:root
        {instance? : Instance selector (app.instance or hostname)}
        {root? : Document root relative to the instance path}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Change the document root for an instance.';

    public function handle(): int
    {
        $selector = $this->stringArgument('instance');
        $root = $this->stringArgument('root');

        if ($selector === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        if ($root === null) {
            return $this->failValidation('root', 'Root is required.');
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->updateRoot($selector, $root);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRootTree($selector, $root);
    }

    private function renderRootTree(string $selector, string $root): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Updating Instance Root',
            [
                ['label' => 'Apply and verify root change', 'doneLabel' => 'Applied and verified root change'],
                [
                    'label' => 'Apply runtime container configuration',
                    'doneLabel' => 'Applied runtime container configuration',
                ],
                ['label' => 'Apply proxy routes', 'doneLabel' => 'Applied proxy routes'],
            ],
            work: function () use ($selector, $root, &$response): array {
                return $response = $this->updateRootForHuman($selector, $root);
            },
            doneFooter: function () use (&$response): string {
                return $this->footerFor($response);
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRootNotes($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function footerFor(array $response): string
    {
        $instance = $this->instanceData($response);
        $name = $this->instanceName($response, $instance);

        if ($this->driftIsPresent($response)) {
            return "Document root for instance '{$name}' updated with drift";
        }

        return $this->changed($response)
            ? "Document root for instance '{$name}' updated"
            : "Document root for instance '{$name}' unchanged";
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderRootNotes(array $response): void
    {
        $instance = $this->instanceData($response);
        $name = $this->instanceName($response, $instance);
        $node = (string) ($instance['node'] ?? '');
        $root = (string) ($instance['root'] ?? '');

        $this->line(
            $this->changed($response)
                ? "  Document root for instance '{$name}' updated to '{$root}'."
                : "  Document root for instance '{$name}' is already '{$root}'.",
        );
        $this->line("  Artifacts successfully re-applied on node '{$node}'.");

        foreach ($this->warnings($response) as $warning) {
            $message = is_string($warning['message'] ?? null) ? trim($warning['message']) : '';

            if ($message === '') {
                continue;
            }

            $this->line("  WARNING: {$message}");

            $code = $warning['code'] ?? null;

            if (is_string($code) && trim($code) !== '') {
                $this->line('    Code:  '.trim($code));
            }

            $nextCommand = $warning['next_command'] ?? null;

            if (is_string($nextCommand) && trim($nextCommand) !== '') {
                $this->line('    Next:  orbit '.trim($nextCommand));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function updateRoot(string $selector, string $root): array
    {
        return $this->gatewayPost($this->apiInstancePath($selector, '/root'), [
            'root' => $root,
        ]);
    }

    /**
     * Run the root change inside the progress tree, re-throwing gateway failures
     * with their operator-facing message so the failed footer renders the
     * documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function updateRootForHuman(string $selector, string $root): array
    {
        try {
            return $this->updateRoot($selector, $root);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function changed(array $response): bool
    {
        $result = $this->successData($response)['result'] ?? null;

        return is_array($result) && ($result['changed'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function instanceData(array $response): array
    {
        $instance = $this->successData($response)['instance'] ?? null;

        return $this->associativeArray($instance) ?? [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $instance
     */
    private function instanceName(array $response, array $instance): string
    {
        $app = $this->successData($response)['app'] ?? null;
        $appName = is_array($app) && is_string($app['name'] ?? null) ? $app['name'] : '';
        $instanceName = is_string($instance['name'] ?? null) ? $instance['name'] : '';

        return $appName !== '' && $instanceName !== '' ? "{$appName}.{$instanceName}" : $instanceName;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function driftIsPresent(array $response): bool
    {
        return $this->warnings($response) !== [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function warnings(array $response): array
    {
        $warnings = $response['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $entries = [];

        foreach ($warnings as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
