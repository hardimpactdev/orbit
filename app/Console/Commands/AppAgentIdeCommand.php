<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\PruneAppWorkspaces;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\AgentIde\ListAgentIdeAdaptersRequest;
use App\Http\Gateway\Requests\Apps\SetAppAgentIdeRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeAdapterChoicesResponse;
use App\Http\Gateway\Responses\Apps\AppAgentIdeResponse;
use App\Models\App;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

#[Signature('app:agent-ide
    {app? : App name or hostname}
    {agent_ide? : Agent IDE adapter (opencode, polyscope, inherit, or none)}
    {--force : Confirm destructive workspace cleanup without prompting}
    {--json : Output JSON}')]
#[Description('Set the default agent IDE for an app')]
class AppAgentIdeCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    public function handle(AppAgentIdeDefaults $defaults, PruneAppWorkspaces $pruneAppWorkspaces): int
    {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $selector = $this->stringArgument('app');

        if ($selector === null && $this->isInteractiveInput()) {
            $selector = $this->promptAppSelector();

            if ($selector instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $selector->errorCode() ?? 'gateway_unavailable',
                    message: $selector->getMessage(),
                    meta: $selector->errorMeta(),
                );
            }
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        $agentIde = $this->stringArgument('agent_ide');

        if ($agentIde === null && $this->isInteractiveInput()) {
            try {
                $agentIde = $this->promptForAgentIde($callerRole, $defaults);
            } catch (GatewayApiException $e) {
                return $this->failCommand(
                    code: $e->errorCode() ?? 'gateway_unavailable',
                    message: $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'Gateway connection is required to read agent IDE adapters.',
                    meta: $e->errorMeta(),
                );
            } catch (Throwable) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required to read agent IDE adapters.',
                    meta: [],
                );
            }
        }

        if ($agentIde === null) {
            return $this->failValidation('agent_ide', 'Agent IDE adapter is required.');
        }

        if ($callerRole === 'control') {
            return $this->forwardSet($selector, $agentIde);
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        if (! $defaults->isSupported($agentIde)) {
            return $this->failCommand(
                code: 'app.unsupported_adapter',
                message: "The adapter \"{$agentIde}\" is not supported.",
                meta: [
                    'adapter' => $agentIde,
                    'supported' => $defaults->supportedAdapters(),
                ],
            );
        }

        if (! $this->wantsJson()) {
            return $this->setAgentIdeForHuman($app, $agentIde, $defaults, $pruneAppWorkspaces);
        }

        $data = $defaults->set($app, $agentIde);

        if ($data['action'] === 'set') {
            $data = $this->maybeCleanupWorkspaces($app, $data, $pruneAppWorkspaces);
        }

        return $this->successCommand($data);
    }

    private function promptAppSelector(): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleApp(label: 'Select an app');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    private function setAgentIdeForHuman(
        App $app,
        string $agentIde,
        AppAgentIdeDefaults $defaults,
        PruneAppWorkspaces $pruneAppWorkspaces,
    ): int {
        $data = null;

        $exitCode = $this->runStepTree(
            'Configuring App Agent IDE',
            [
                [
                    'key' => 'validate_adapter',
                    'label' => 'Validate adapter',
                    'doneLabel' => 'Validated adapter',
                    'run' => fn (): string => $agentIde,
                ],
                [
                    'key' => 'check_cleanup',
                    'label' => 'Check for workspace cleanup',
                    'doneLabel' => 'Checked for workspace cleanup',
                    'run' => fn (): string => 'ready',
                ],
                [
                    'key' => 'apply_agent_ide',
                    'label' => 'Apply and verify app agent IDE',
                    'doneLabel' => 'Applied and verified app agent IDE',
                    'run' => function () use ($app, $agentIde, $defaults, $pruneAppWorkspaces, &$data): string {
                        $data = $defaults->set($app, $agentIde);

                        if ($data['action'] === 'set') {
                            $data = $this->maybeCleanupWorkspaces($app, $data, $pruneAppWorkspaces);
                        }

                        return (string) ($data['agent_ide']['effective_adapter'] ?? 'none');
                    },
                ],
            ],
            doneFooter: "App '{$app->name}' agent IDE configured",
            failFooter: "Failed to configure app '{$app->name}' agent IDE.",
        );

        if ($exitCode !== self::SUCCESS || ! is_array($data)) {
            return self::FAILURE;
        }

        return $this->successCommand($data);
    }

    private function promptForAgentIde(string $callerRole, AppAgentIdeDefaults $defaults): string
    {
        $options = collect($this->agentIdeChoices($callerRole, $defaults))
            ->mapWithKeys(fn (string $adapter): array => [$adapter => $this->agentIdeChoiceLabel($adapter)])
            ->all();

        /** @var string $selected */
        $selected = select(
            label: 'Select agent IDE adapter',
            options: $options,
            default: 'inherit',
        );

        return $selected;
    }

    /**
     * @return list<string>
     */
    private function agentIdeChoices(string $callerRole, AppAgentIdeDefaults $defaults): array
    {
        if ($callerRole !== 'control') {
            return $defaults->supportedAdapters();
        }

        /** @var AgentIdeAdapterChoicesResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListAgentIdeAdaptersRequest('app'))
            ->dto();

        return $dto->supportedInputs();
    }

    private function agentIdeChoiceLabel(string $adapter): string
    {
        return match ($adapter) {
            'inherit' => 'Inherit node default',
            'none' => 'None',
            default => $adapter,
        };
    }

    private function forwardSet(string $selector, string $agentIde): int
    {
        try {
            /** @var AppAgentIdeResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new SetAppAgentIdeRequest($selector, $agentIde, $this->option('force') === true))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to set app agent IDE defaults.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to set app agent IDE defaults.',
                meta: [],
            );
        }

        return $this->successCommand($dto->data);
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }  $data
     * @return array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }
     */
    private function maybeCleanupWorkspaces(App $app, array $data, PruneAppWorkspaces $pruneAppWorkspaces): array
    {
        $previousAdapter = $data['previous_adapter'];
        $currentEffective = $data['agent_ide']['effective_adapter'];

        if ($previousAdapter === null || $previousAdapter === $currentEffective) {
            return $data;
        }

        try {
            $dryRun = $pruneAppWorkspaces->handle($app, dryRun: true, adapterName: $previousAdapter);
            $staleWorkspaces = $dryRun['stale_workspaces'];

            if ($staleWorkspaces === []) {
                return $data;
            }

            $force = $this->option('force') === true;
            $count = count($staleWorkspaces);

            if (! $force && $this->isInteractiveInput()) {
                $confirmed = confirm(
                    label: "This will remove {$count} workspace(s) managed by the previous adapter '{$previousAdapter}'. Continue?",
                    default: false,
                );

                if (! $confirmed) {
                    $this->warn('Adapter switch completed without workspace cleanup.');

                    return $data;
                }
            }

            if (! $force && ! $this->isInteractiveInput()) {
                $this->warn("Skipped workspace cleanup: {$count} workspace(s) managed by '{$previousAdapter}' remain. Use --force to remove them.");

                return $data;
            }

            $result = $pruneAppWorkspaces->handle($app, dryRun: false, adapterName: $previousAdapter);
            $removed = array_values(array_filter(
                $result['stale_workspaces'],
                fn (array $ws): bool => $ws['removed'],
            ));

            $data['cleanup']['workspaces_removed'] = array_map(
                fn (array $ws): string => $ws['name'],
                $removed,
            );
        } catch (\RuntimeException) {
            // Adapter does not support workspace discovery; skip cleanup.
        }

        return $data;
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values()
            ->first();
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }  $data
     */
    private function successCommand(array $data): int
    {
        if (! $this->wantsJson()) {
            if ($data['action'] === 'converged') {
                $this->line($this->humanConvergedLine($data));

                return self::SUCCESS;
            }

            $this->line($this->humanSetLine($data));
            $this->renderCleanupSummary($data);

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }  $data
     */
    private function humanSetLine(array $data): string
    {
        $name = (string) ($data['app']['name'] ?? '');
        $node = (string) ($data['app']['node'] ?? '');
        $agentIde = $data['agent_ide'];
        $adapter = $agentIde['adapter'];
        $effectiveAdapter = $agentIde['effective_adapter'];

        if ($adapter === null && $agentIde['source'] === 'node' && $effectiveAdapter !== null) {
            return "App `{$name}` agent IDE set to inherit (effective: `{$effectiveAdapter}` from node `{$node}`)";
        }

        if ($adapter === null) {
            return "App `{$name}` agent IDE set to inherit (effective: none)";
        }

        if ($adapter === 'none') {
            return "App `{$name}` agent IDE set to none (effective: none)";
        }

        return "App `{$name}` agent IDE set to `{$adapter}` (effective: `{$effectiveAdapter}`)";
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }  $data
     */
    private function humanConvergedLine(array $data): string
    {
        $name = (string) ($data['app']['name'] ?? '');
        $agentIde = $data['agent_ide'];
        $adapter = $agentIde['adapter'] ?? null;

        if ($adapter === null && $agentIde['source'] === 'node') {
            return "App `{$name}` agent IDE already set to inherit";
        }

        if ($adapter === null) {
            return "App `{$name}` agent IDE already set to none";
        }

        return "App `{$name}` agent IDE already set to `{$adapter}`";
    }

    /**
     * @param  array{
     *     cleanup: array{workspaces_removed: list<string>}
     * }  $data
     */
    private function renderCleanupSummary(array $data): void
    {
        $removed = $data['cleanup']['workspaces_removed'];

        if ($removed === []) {
            return;
        }

        $count = count($removed);
        $this->line("Removed {$count} stale workspaces during adapter switch:");

        foreach ($removed as $workspace) {
            $this->line("- {$workspace}");
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
