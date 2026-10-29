<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCommandContracts;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\AgentCommands\PiArtifactDelivery;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;

/** A named invocation through the Operation's trusted Incus host; never a second target or script source. */
final readonly class TaskVmGuestCommand implements OperationEffectTransport
{
    use TaskVmTargetReceipt;
    use TaskVmTransportSchema;

    public function __construct(private GuestAgentCommandRunner $agents, private TaskVmSettings $settings, private PiArtifactDelivery $artifacts,
        private RecipeImageBuildSteps $eligibility, private RecipeLoader $recipes, private RecipeImageInput $recipesInput) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $command = ValidatedData::string($input['command'] ?? null);
        if (! in_array($command, [...TaskVmCommandContracts::GuestCommands, ...TaskVmCommandContracts::SpecialGuestCommands], true)
            && ! ($operation->kind === 'task_vm.cache_seed' && $operation->target_type === 'task_vm' && $command === 'package-cache.seed')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The guest command is not declared.', 422);
        }
        $decoded = json_decode(ValidatedData::string($input['parameters_json']), flags: JSON_THROW_ON_ERROR);
        if (! $decoded instanceof \stdClass || AgentSchemas::errors($decoded, TaskVmCommandContracts::guestParameters($command)) !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The guest command input does not match its agent schema.', 422);
        }
        $parameters = (array) $decoded;
        $guest = ValidatedData::object($input['guest'] ?? []);
        $host = Node::query()->findOrFail($operation->node_id);
        $configured = $this->settings->host($host->id);
        $owner = $operation->target_type === 'task_vm_image' ? TaskVmImageBuild::query()->findOrFail($operation->target_id) : TaskVm::query()->findOrFail($operation->target_id);
        $names = $owner instanceof TaskVmImageBuild ? [$owner->builder_vm_name, $owner->builder_vm_name.'-smoke'] : [$owner->name];
        if ($owner->host_node_id !== $host->id || ($guest['project'] ?? null) !== $configured->project || ! in_array($guest['name'] ?? null, $names, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The guest does not belong to this Operation.', 422);
        }
        $target = AgentGuest::incus($host, ValidatedData::string($guest['project']), ValidatedData::string($guest['name']));
        if ($owner instanceof TaskVmImageBuild) {
            $this->eligibility->assertCurrent($owner);
            $phase = $operation->input['phase'] ?? null;
            $expectedName = $owner->builder_vm_name.(in_array($phase, ['smoke', 'topology-seed'], true) ? '-smoke' : '');
            if ($owner->state !== TaskVmImageBuildState::Running || $owner->attempt !== ($operation->input['attempt'] ?? null) || $owner->cleanup_started_at !== null || $guest['name'] !== $expectedName) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The guest does not match the current image phase.', 422);
            }
            $recipe = $this->recipes->decode((string) $owner->recipe_json);
            if (in_array($command, ['image.start-phase', 'image.verify'], true)) {
                $expectedRecipe = $this->recipesInput->payload($target, $recipe, 'base');
                $actual = $command === 'image.verify' ? $parameters : ($parameters['recipe'] ?? null);
                if ($this->ordered($actual) !== $this->ordered($expectedRecipe)) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The guest recipe differs from the admitted image recipe.', 422);
                }
            }
            if (in_array($command, ['image.start-phase', 'image.restart-phase', 'image.inspect-phase'], true)) {
                $key = (array) ($parameters['key'] ?? []);
                if ($this->ordered($key) !== $this->ordered(['identity' => $owner->recipe_identity, 'profile' => 'base', 'phase' => $phase, 'execution' => (string) $owner->id])) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The phase key differs from the image attempt.', 422);
                }
            }
            if ($command === 'pi.upload-guest') {
                $architecture = MachineRecipe::architecture((string) $host->architecture) ?? throw new \LogicException('Unsupported image architecture.');
                $pin = $recipe->tools['pi']->artifacts[$architecture] ?? null;
                if ($pin === null || $parameters['artifact'] !== config()->string('orbit.home').'/sandbox-artifacts/'.$pin->location || $parameters['sha256'] !== $pin->digest) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The artifact is not the image recipe\'s pinned Pi artifact.', 422);
                }
            }
        }
        if ($command === 'agent.install-guest') {
            $this->agents->installGuest($target);

            return ['ready' => true];
        }
        if ($command === 'pi.upload-guest') {
            $this->artifacts->deliver($target, ValidatedData::string($parameters['artifact']), ValidatedData::string($parameters['sha256']));

            return ['ready' => true];
        }

        return $this->agents->runGuest($target, $command, $this->effectful($operation, $run) ? AgentEffectReceipts::input($run, $parameters) : $parameters);
    }

    /** @param array<string,mixed> $run */
    public function effectful(Operation $operation, array $run): bool
    {
        $input = ValidatedData::object($run['input']);
        $command = $input['command'] ?? null;

        return is_string($command) && ! in_array($command, ['guest.cloud-init', 'guest.fingerprint', 'image.inspect-phase', ...TaskVmCommandContracts::SpecialGuestCommands], true);
    }

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array{effect_key:string,state:string,result:array<string,mixed>|null,sanitized:bool,effect_nonce:string|null,effect_created_at:int|null,exit_code:int|null,finished_at:string|null}
     */
    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $host = Node::query()->findOrFail($operation->node_id);
        $owner = $operation->target_type === 'task_vm_image' ? TaskVmImageBuild::query()->findOrFail($operation->target_id) : TaskVm::query()->findOrFail($operation->target_id);
        $guest = ValidatedData::object($input['guest']);
        $configured = $this->settings->host($host->id);
        $names = $owner instanceof TaskVmImageBuild ? [$owner->builder_vm_name, $owner->builder_vm_name.'-smoke'] : [$owner->name];
        if ($owner->host_node_id !== $host->id || $guest['project'] !== $configured->project || ! in_array($guest['name'], $names, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The receipt guest is not owned by this Operation.', 422);
        }

        return $this->targetReceipt(AgentGuest::incus($host, ValidatedData::string($guest['project']), ValidatedData::string($guest['name'])), ValidatedData::string($input['command']), ValidatedData::string($run['effect_key'] ?? null));
    }

    private function ordered(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$entry) {
            $entry = $this->ordered($entry);
        }
        unset($entry);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
