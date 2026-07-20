<?php

declare(strict_types=1);

namespace App\Commands\Codex;

use App\Commands\Concerns\RequiresLocalExtension;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class CodexAppCommand extends GatewayCommand
{
    use RequiresLocalExtension;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'codex:app
        {action? : Codex action: add, remove, or list}
        {project? : Project name or hostname}
        {--node= : Target node running Codex App}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register Orbit projects in Codex App on a target node.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $action = $this->stringArgument('action');
        $project = $this->stringArgument('project');
        $node = $this->stringOption('node');

        if ($action === null || ! in_array($action, ['add', 'remove', 'list'], strict: true)) {
            return $this->failValidation('action', 'Action must be add, remove, or list.');
        }

        if ($node === null) {
            return $this->failValidation('node', 'Node is required.');
        }

        if (in_array($action, ['add', 'remove'], strict: true) && $project === null) {
            return $this->failValidation('project', 'Project is required.');
        }

        if ($action === 'list' && $project !== null) {
            return $this->failValidation('project', 'Project must be omitted when listing Codex App projects.');
        }

        try {
            $response = match ($action) {
                'add' => $this->gatewayPost('/api/codex/apps/'.rawurlencode($project), ['node' => $node]),
                'remove' => $this->gatewayDelete('/api/codex/apps/'.rawurlencode($project), ['node' => $node]),
                'list' => $this->gatewayGet('/api/codex/projects', ['node' => $node]),
            };
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    protected function extensionSlug(): string
    {
        return 'codex';
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }
}
