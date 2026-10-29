<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use App\Services\Git\GitRepositoryOriginPolicy;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use App\Support\Console\TerminalText;
use App\Support\GatewayFailureRenderer;
use Laravel\Prompts\ConfirmPrompt;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class RegisterInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:register
        {--path= : Existing Git checkout or worktree; defaults to the current directory}
        {--include-worktrees : Adopt the checkout and every linked worktree}
        {--project= : Existing numeric Project ID}
        {--name= : Optional Instance name; default, or the Project default branch name, means the default development source}
        {--root= : Relative web-root override for this Instance}
        {--domain= : Optional explicit Route domain}
        {--yes : Confirm source ownership transfer without prompting}
        {--setup : Run the Project setup steps after adoption}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Adopt the current Git source as a managed Instance of an existing Project.';

    public function handle(
        GitRegistrationDiscovery $git,
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $workingDirectory = getcwd();
        $requestedPath = $this->stringOption('path') ?? ($workingDirectory === false ? '' : $workingDirectory);
        $inspection = $this->progressDisplay('Inspect source');
        $inspection->admit('inspect', 'Inspect source', 'Inspecting source', 'Inspected source');
        $facts = $inspection->during('inspect', fn (): ?GitRegistrationFacts => $git->inspect($requestedPath));
        $validSource = $facts instanceof GitRegistrationFacts && GitRepositoryOriginPolicy::isSafe($facts->repositoryUrl);
        $inspection->complete('inspect', $validSource ? ProgressState::Success : ProgressState::Failure);
        $inspection->finish($validSource ? 'Source inspected.' : 'Source inspection failed.');

        if (! $facts instanceof GitRegistrationFacts) {
            return $this->renderGatewayFailure(
                'instance.source_invalid',
                'The current path is not a supported Git checkout or worktree.',
            );
        }

        if (! GitRepositoryOriginPolicy::isSafe($facts->repositoryUrl)) {
            return $this->renderGatewayFailure(
                'instance.source_invalid',
                'The current path is not a supported Git checkout or worktree.',
            );
        }

        $values = $this->requestedValues();

        if ($values === null) {
            return self::FAILURE;
        }

        if (! $this->confirmOwnership($facts)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new RegisterInstanceRequest(
            sourcePath: $facts->path, includeWorktrees: $this->option('include-worktrees') === true,
            projectId: $values['projectId'], instanceName: $this->stringOption('name'), root: $values['root'],
            domain: $this->stringOption('domain'), setup: $this->option('setup') === true,
            idempotencyKey: $this->stringOption('idempotency-key'),
        ), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Register Instance', 'Instance registration')
            : self::FAILURE;
    }

    /**
     * Registration adopts a source for an existing Project only, so the CLI sends no Project values
     * ([Projects](/reference/projects#registration-needs-a-project)).
     *
     * @return array{projectId: ?int, root: ?string}|null
     */
    private function requestedValues(): ?array
    {
        $projectId = $this->stringOption('project');
        $projectIdValue = $projectId === null ? null : filter_var($projectId, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
        ]]);

        if ($projectId !== null && ! is_int($projectIdValue)) {
            $this->renderGatewayFailure('project.id_invalid', 'Project ID must be a positive integer.');

            return null;
        }

        $root = $this->stringOption('root');

        if ($root !== null && self::rootError($root) !== null) {
            GatewayFailureRenderer::write($this, 'validation.failed', 'The request data is invalid.', details: [
                'root' => ['The root must be a normalized relative web path.'],
            ]);

            return null;
        }

        return ['projectId' => is_int($projectIdValue) ? $projectIdValue : null, 'root' => $root];
    }

    private function confirmOwnership(GitRegistrationFacts $facts): bool
    {
        if ($this->option('yes') === true) {
            return true;
        }
        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure('input.confirmation_required', 'Supply --yes to transfer this source to Orbit ownership.');

            return false;
        }
        $scope = $this->option('include-worktrees') === true ? ' and all linked worktrees' : '';
        try {
            if ($this->commandPrompts()->run(fn (): ConfirmPrompt => new ConfirmPrompt(
                TerminalText::safe("Transfer source [{$facts->path}]{$scope} to Orbit ownership, allowing relocation and later removal?"),
                default: false,
            )) === true) {
                return true;
            }
        } catch (PromptAborted|ConsoleInterrupted) {
            // Registration keeps its existing cancellation code.
        }
        $this->renderGatewayFailure('instance.registration_cancelled', 'Registration was cancelled.');

        return false;
    }

    private static function rootError(string $root): ?string
    {
        $valid = $root !== '' && strlen($root) <= 255 && array_all(explode('/', $root),
            static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..' && preg_match('/\\A[A-Za-z0-9._-]+\\z/D', $part) === 1);

        return $valid ? null : 'Enter a normalized relative web path.';
    }
}
