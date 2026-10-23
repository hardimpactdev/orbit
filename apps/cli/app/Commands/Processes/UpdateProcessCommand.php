<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\UpdateProcessRequest;
use Orbit\Sdk\Requests\Projects\ListProcessDefinitionsRequest;
use Orbit\Sdk\Requests\Projects\UpdateProcessDefinitionRequest;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionsResponse;

final class UpdateProcessCommand extends ProcessCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'process:update
        {name? : Process ID or Project process definition name}
        {--project= : Numeric Project ID}
        {--for= : Comma-separated definition environments}
        {--runtime= : systemd or docker}
        {--command=* : One command argument; repeat for each argv item}
        {--image= : Docker image}
        {--working-directory= : Runtime working directory}
        {--environment=* : Docker NAME=VALUE; repeat as needed}
        {--port=* : Docker HOST:CONTAINER[/tcp|udp]; repeat as needed}
        {--volume=* : Docker SOURCE:TARGET[:ro]; repeat as needed}
        {--restart= : never, on-failure, always, or unless-stopped}
        {--keep-alive : Keep running through app-dev idle hibernation}
        {--no-restart-on-deploy : Skip automatic restart after production activation}
        {--user= : Existing non-root account for a Node systemd Process}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update an installed Process specification or one Project process definition.';

    #[\Override]
    protected $help = 'Installed updates preserve omitted fields. Public and private Process Routes stay active when their single resolved TCP listener keeps the same address and host port. Otherwise the Gateway returns process.route_upstream_changed (409), naming the Route. Process removal remains refused with process.has_routes.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $installed = ! $this->providedProjectOption();
        if (! $installed && $this->stringOption('user') !== null) {
            return $this->renderGatewayFailure('process.option_invalid', 'The --user option is only available for an installed Process.');
        }
        if ($this->argument('name') === null && $this->consoleMode()->mayPrompt) {
            $connector = $this->gatewayConnector($repository, $connectors);
            if ($connector === null) {
                return self::FAILURE;
            }
            $rows = [];
            $emptyCode = $installed ? 'process.id_invalid' : 'process.name_required';
            $emptyMessage = $installed ? 'No Processes found.' : 'No Process definitions found.';
            if ($installed) {
                $response = $this->sendWithProgress($connector, new ListProcessesRequest,
                    ProcessesResponse::class, ['List Processes', 'Loading Processes', 'Loaded Processes']);
                if (! $response instanceof ProcessesResponse) {
                    return self::FAILURE;
                }
                foreach ($response->processes as $process) {
                    $rows[$process->id] = [(string) $process->id, $process->name, $process->targetType.':'.$process->targetId];
                }
            } else {
                $projectId = $this->projectIdOption();
                if (! is_int($projectId)) {
                    return self::FAILURE;
                }
                $response = $this->sendWithProgress($connector, new ListProcessDefinitionsRequest($projectId),
                    ProjectRuntimeDefinitionsResponse::class, ['List Process definitions', 'Loading Process definitions', 'Loaded Process definitions']);
                if (! $response instanceof ProjectRuntimeDefinitionsResponse) {
                    return self::FAILURE;
                }
                foreach ($response->definitions as $definition) {
                    $rows[$definition->name] = [$definition->name];
                }
            }
            if ($rows === []) {
                return $this->renderGatewayFailure($emptyCode, $emptyMessage);
            }
            $this->input->setArgument('name', (string) $this->commandPrompts()->selectEntity(
                $installed ? 'Process' : 'Process definition', $installed ? ['ID', 'Name', 'Owner'] : ['Name'], $rows));
        }
        $name = $this->stringArgument('name', $installed ? 'Process ID' : 'Process definition name', $installed ? 'process.id_invalid' : 'process.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $runtime = $this->stringOption('runtime') ?? ($installed ? null : 'systemd');

        if ($runtime !== null && ! in_array($runtime, ['systemd', 'docker'], strict: true)) {
            return $this->renderGatewayFailure(
                'process.runtime_invalid',
                'Process runtime must be systemd or docker.',
            );
        }

        $command = $this->stringListOption('command');
        if (! $installed && $command === [] && $this->consoleMode()->mayPrompt) {
            do {
                $argument = $this->commandPrompts()->run(fn () => new TextPrompt(
                    label: 'Command argument', required: $command === [],
                    validate: static fn (string $value): ?string => strlen($value) > 4096 || preg_match('/[\\x00\\r\\n]/', $value) === 1 ? 'Invalid command argument.' : null,
                    hint: $command === [] ? 'One literal argv item; no shell evaluation.' : 'Leave empty to finish.',
                ));
                if (! is_string($argument) || $argument === '') {
                    break;
                }
                $command[] = $argument;
            } while (count($command) < 64);
        }

        if (
            count($command) > 64
            || array_any(
                $command,
                static fn (string $argument): bool => strlen($argument) > 4096
                || preg_match('/[\x00\r\n]/', $argument) === 1,
            )
        ) {
            return $this->renderGatewayFailure(
                'process.command_invalid',
                'Process command arguments are invalid.',
            );
        }

        $restartPolicy = $this->stringOption('restart') ?? ($installed ? null : 'never');

        if (
            $restartPolicy !== null
            && ! in_array($restartPolicy, ['never', 'on-failure', 'always', 'unless-stopped'], strict: true)
        ) {
            return $this->renderGatewayFailure(
                'process.restart_policy_invalid',
                'Invalid process restart policy.',
            );
        }

        $image = $this->stringOption('image');
        if (! $installed && $runtime === 'docker' && $image === null && $this->consoleMode()->mayPrompt) {
            $answer = $this->commandPrompts()->run(fn () => new TextPrompt(
                label: 'Docker image', required: true,
                validate: static fn (string $value): ?string => strlen($value) > 255 || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._\\/:@-]*\\z/D', $value) !== 1 ? 'Invalid Docker image.' : null,
            ));
            $image = is_string($answer) ? $answer : null;
        }
        $workingDirectory = $this->stringOption('working-directory');
        $environmentWasProvided = $this->input->hasParameterOption('--environment');
        $environment = $this->environment();

        if ($environment === null) {
            return self::FAILURE;
        }

        $volumesWereProvided = $this->input->hasParameterOption('--volume');
        $volumes = $this->volumes();

        if ($volumes === null) {
            return self::FAILURE;
        }

        $portsWereProvided = $this->input->hasParameterOption('--port');
        $ports = $this->ports();

        if ($ports === null) {
            return self::FAILURE;
        }

        $projectId = $this->projectIdOption();

        if ($projectId === false) {
            return self::FAILURE;
        }

        if ($projectId === null) {
            $id = filter_var($name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                return $this->renderGatewayFailure('process.id_invalid', 'Process ID must be a positive integer.');
            }
            if ($this->providedOption('for')) {
                return $this->renderGatewayFailure('process.option_invalid', 'The --for option requires --project.');
            }
            if (($this->input->hasParameterOption('--command') && $command === []) || ($command !== [] && $runtime === 'systemd' && ! str_starts_with($command[0], '/'))) {
                return $this->renderGatewayFailure('process.command_invalid', 'Process command must be nonempty; systemd needs an absolute executable.');
            }
            if ($image !== null && (preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._\\/:@-]*\\z/D', $image) !== 1)) {
                return $this->renderGatewayFailure('process.image_invalid', 'A valid Docker image is required.');
            }
            if ($workingDirectory !== null && (strlen($workingDirectory) > 4096 || preg_match('/\\A\\/(?!.*(?:^|\\/)\\.\\.(?:\\/|$))[^\\x00\\r\\n]*\\z/D', $workingDirectory) !== 1)) {
                return $this->renderGatewayFailure('process.working_directory_invalid', 'Process working directory must be a safe absolute path.');
            }
            $user = $this->stringOption('user');
            if (($runtime === 'systemd' && ($environmentWasProvided || $portsWereProvided || $volumesWereProvided || $image !== null)) || ($runtime === 'docker' && $user !== null)) {
                return $this->renderGatewayFailure('process.option_invalid', 'Options do not match the Process runtime.');
            }
            if ($user !== null && ($user === 'root' || preg_match('/\\A[a-z_][a-z0-9_-]{0,31}\\z/D', $user) !== 1)) {
                return $this->renderGatewayFailure('process.user_invalid', 'Process user must be a valid non-root account name.');
            }
            $connector = $this->gatewayConnector($repository, $connectors);
            if ($connector === null) {
                return self::FAILURE;
            }
            $response = $this->sendWithProgress($connector,
                new UpdateProcessRequest(
                    $id, $runtime, $command === [] ? null : $command, $image, $workingDirectory,
                    $environmentWasProvided ? $environment : null, $portsWereProvided ? $ports : null,
                    $volumesWereProvided ? $volumes : null, $restartPolicy,
                    $this->option('keep-alive') === true ? true : null, $user,
                    restartOnDeploy: $this->option('no-restart-on-deploy') === true ? false : null,
                ),
                ProcessResponse::class,
                ['Update Process', 'Updating Process', 'Updated Process']);

            return $response instanceof ProcessResponse
                ? $this->renderProcess($response, 'Updated Process') : self::FAILURE;
        }
        if ($this->option('no-restart-on-deploy') === true) {
            return $this->renderGatewayFailure('process.option_invalid', 'Project definitions do not accept --no-restart-on-deploy.');
        }
        if ($this->providedOption('user')) {
            return $this->renderGatewayFailure('process.option_invalid', 'Project definitions do not accept --user.');
        }

        $environments = $this->definitionEnvironments(errorCode: 'process.option_invalid');

        if ($environments === false) {
            return self::FAILURE;
        }

        $spec = [
            'runtime' => $runtime,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'keep_alive' => $this->option('keep-alive') === true,
        ];

        if ($image !== null) {
            $spec['image'] = $image;
        }

        if ($workingDirectory !== null) {
            $spec['working_directory'] = $workingDirectory;
        }

        if ($environmentWasProvided) {
            $spec['environment'] = $environment;
        }

        if ($portsWereProvided) {
            $spec['ports'] = $ports;
        }

        if ($volumesWereProvided) {
            $spec['volumes'] = $volumes;
        }

        try {
            $definition = json_encode(
                [
                    'name' => $name,
                    'environments' => $environments,
                    'spec' => $spec,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return $this->renderGatewayFailure(
                'process.definition_invalid',
                'Process definition could not be encoded.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new UpdateProcessDefinitionRequest($projectId, $name, $definition),
            ProjectRuntimeDefinitionResponse::class,
            ['Update Process definition', 'Updating Process definition', 'Updated Process definition'],
        );

        return $response instanceof ProjectRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Process')
            : self::FAILURE;
    }
}
