<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCommandContracts;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\AgentCommands\PiArtifactDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** One owned API or guest effect per receipt. Swamp owns the build order and all polling. */
final readonly class TaskVmUpCloudImageCommand implements OperationEffectTransport
{
    use TaskVmTargetReceipt;
    use TaskVmTransportSchema;

    public function __construct(private TaskVmUpCloudImageRead $reads, private UpCloudApi $api, private UpCloudGuest $guest,
        private SshKeyProvider $keys, private TaskVmCloudInit $cloudInit, private GuestAgentCommandRunner $agents,
        private RecipeLoader $recipes, private RecipeImageInput $inputs, private PiArtifactDelivery $artifacts, private AgentContract $contract) {}

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $action = ValidatedData::string($input['action']);
        $build = $this->reads->build($operation, ! in_array($action, ['delete-builder', 'delete-smoke', 'hard-stop', 'discard-template'], true));
        $slot = ValidatedData::string($input['slot'] ?? 'builder');
        $intent = $build->intent ?? throw new \LogicException('The image intent is missing.');
        if ($action === 'prepare') {
            if ($build->bootstrap === null) {
                $build->forceFill(['bootstrap' => ['builder' => $this->guest->generateHostKey($intent['name']), 'smoke' => $this->guest->generateHostKey($intent['name'].'-smoke')]])->save();
            }

            return ['ready' => true];
        }
        if ($action === 'guest') {
            $command = ValidatedData::string($input['command']);
            $parameters = json_decode(ValidatedData::string($input['parameters_json']), flags: JSON_THROW_ON_ERROR);
            if (! $parameters instanceof \stdClass || AgentSchemas::errors($parameters, TaskVmCommandContracts::guestParameters($command)) !== []) {
                $this->refuse();
            }
            $parameters = (array) $parameters;
            $target = $this->reads->target($build, $slot);
            $recipe = $this->recipes->decode($intent['recipe_json']);
            if (in_array($command, ['image.start-phase', 'image.verify'], true)) {
                $actual = $command === 'image.verify' ? $parameters : $parameters['recipe'] ?? null;
                if ($this->ordered($actual) !== $this->ordered($this->inputs->payload($target, $recipe, 'base'))) {
                    $this->refuse();
                }
            }
            if ($command === 'image.start-phase') {
                $key = (array) $parameters['key'];
                if (($key['identity'] ?? null) !== $build->recipe_identity || ($key['execution'] ?? null) !== 'upcloud-'.$build->id || ($key['profile'] ?? null) !== 'base') {
                    $this->refuse();
                }
            }
            if ($command === 'agent.install-guest') {
                $this->agents->installGuest($target);

                return ['ready' => true];
            }
            if ($command === 'pi.upload-guest') {
                $pin = $recipe->tools['pi']->artifacts['amd64'] ?? null;
                if ($pin === null || $parameters['artifact'] !== config()->string('orbit.home').'/sandbox-artifacts/'.$pin->location || $parameters['sha256'] !== $pin->digest) {
                    $this->refuse();
                }
                $this->artifacts->deliver($target, $parameters['artifact'], $parameters['sha256']);

                return ['ready' => true];
            }

            return $this->agents->runGuest($target, $command, $this->effectful($operation, $run) ? AgentEffectReceipts::input($run, $parameters) : $parameters);
        }
        $servers = new UpCloudServers($this->api->authenticated(ValidatedData::string($input['token'])));
        $name = $intent['name'].($slot === 'smoke' ? '-smoke' : '');
        $stamp = $intent['stamp'];
        if ($action === 'launch') {
            $server = $servers->find($name, UpCloudServers::ImageLabel, $stamp, $build->resources[$slot]['uuid'] ?? null);
            if ($server === null) {
                $bootstrap = ValidatedData::object($input['bootstrap']);
                $rawKeys = ValidatedData::object($bootstrap[$slot]);
                $keys = ['private' => ValidatedData::string($rawKeys['private']), 'public' => ValidatedData::string($rawKeys['public'])];
                $template = $slot === 'smoke' ? $build->template_uuid : $intent['source'];
                if (! is_string($template)) {
                    $this->refuse();
                }
                $data = $slot === 'smoke' ? $this->cloudInit->render($this->keys->publicKey()) : $this->cloudInit->renderImageBuilder($this->keys->publicKey());
                $uuid = $servers->create($name, UpCloudServers::ImageLabel, $stamp, $template, TaskVmCloudInit::withHostKey($data, $keys), $this->keys->publicKey());
                $resources = $build->resources;
                $resources[$slot] = ['uuid' => $uuid];
                $build->forceFill(['resources' => $resources])->save();
            }
        } elseif ($action === 'stop' || $action === 'hard-stop') {
            $server = $servers->find($name, UpCloudServers::ImageLabel, $stamp);
            if ($server !== null && $server->state !== 'stopped') {
                $servers->stop($server->uuid, $action === 'hard-stop' ? null : 120);
            }
        } elseif ($action === 'templatize') {
            $title = TaskVmUpCloud::TemplatePrefix.$stamp.TaskVmUpCloud::Unverified;
            $templates = array_keys(array_filter($servers->templates(), static fn (string $value): bool => $value === $title));
            if (count($templates) > 1) {
                $this->refuse();
            }
            if ($build->template_uuid === null) {
                $server = $servers->find($name, UpCloudServers::ImageLabel, $stamp);
                if ($server === null || $server->state !== 'stopped') {
                    $this->refuse();
                }
                $uuid = $templates[0] ?? $servers->beginTemplate($server->disk, $title);
                $build->forceFill(['template_uuid' => $uuid])->save();
            }
        } elseif (in_array($action, ['delete-builder', 'delete-smoke'], true)) {
            $slot = $action === 'delete-smoke' ? 'smoke' : 'builder';
            $servers->requestDelete($intent['name'].($slot === 'smoke' ? '-smoke' : ''), UpCloudServers::ImageLabel, $stamp, $build->resources[$slot]['uuid'] ?? null);
        } elseif ($action === 'promote') {
            DB::transaction(function () use ($operation, $build, $servers, $stamp): void {
                DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
                $this->reads->build($operation);
                $parent = Operation::query()->findOrFail(ValidatedData::integer($operation->getAttribute('parent_operation_id')));
                foreach (['smoke-check' => 'task_vm.upcloud_guest_image_check', 'smoke-verify' => 'task_vm.upcloud_guest_image_verify'] as $key => $kind) {
                    $proof = app(OperationChildren::class)->requireSucceeded($parent, $key);
                    if ($proof->kind !== $kind || $proof->target_type !== 'task_vm_upcloud_image' || $proof->target_id !== $build->id || ($proof->input['slot'] ?? null) !== 'smoke' || ($proof->result['ready'] ?? null) !== true) {
                        throw new ResourceOperationException('operation.completion_unproven', 'The template has no matching smoke proof.', 409);
                    }
                }
                foreach (['verify' => 'task_vm.upcloud_guest_image_verify', 'firewall' => 'task_vm.upcloud_guest_image_firewall', 'clean' => 'task_vm.upcloud_guest_image_clean', 'templatize' => 'task_vm.upcloud_image_templatize'] as $key => $kind) {
                    $proof = app(OperationChildren::class)->requireSucceeded($parent, $key);
                    if ($proof->kind !== $kind || $proof->target_type !== 'task_vm_upcloud_image' || $proof->target_id !== $build->id || ($proof->result['ready'] ?? null) !== true || ($key !== 'templatize' && ($proof->input['slot'] ?? null) !== 'builder')) {
                        throw new ResourceOperationException('operation.completion_unproven', 'The template has no matching preparation proof.', 409);
                    }
                    if ($key === 'templatize' && ($proof->result['template_uuid'] ?? null) !== $build->template_uuid) {
                        throw new ResourceOperationException('operation.completion_unproven', 'The template proof names another image.', 409);
                    }
                }
                $servers->renameTemplate((string) $build->template_uuid, TaskVmUpCloud::TemplatePrefix.$stamp, $build->recipe_identity);
                $resources = $build->resources;
                $resources['promoted'] = true;
                $build->forceFill(['resources' => $resources])->save();
            });
        } elseif ($action === 'prune-template') {
            $uuid = ValidatedData::string($input['template_uuid']);
            $verified = array_filter($servers->templates(), static fn (string $title): bool => ! str_ends_with($title, TaskVmUpCloud::Unverified));
            arsort($verified, SORT_STRING);
            $pinned = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->whereNull('provider_id')->pluck('image_fingerprint')->all();
            if (in_array($uuid, array_slice(array_keys($verified), 2), true) && ! in_array($uuid, $pinned, true)) {
                $servers->deleteTemplate($uuid);
            }
        } elseif ($action === 'discard-template') {
            $template = $build->template_uuid;
            $title = $template === null ? null : ($servers->templates()[$template] ?? null);
            if ($template !== null && $title === TaskVmUpCloud::TemplatePrefix.$stamp.TaskVmUpCloud::Unverified) {
                $servers->deleteTemplate($template);
            }
        } else {
            $this->refuse();
        }

        return ['ready' => true, 'template_uuid' => $build->template_uuid];
    }

    /** @param array<string,mixed> $run */
    public function effectful(Operation $operation, array $run): bool
    {
        $input = ValidatedData::object($run['input']);

        $command = $input['command'] ?? null;

        return ($input['action'] ?? null) === 'guest' && is_string($command) && ! in_array($command, TaskVmCommandContracts::SpecialGuestCommands, true) && $this->contract->command($command)->mutates;
    }

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array{effect_key:string,state:string,result:array<string,mixed>|null,sanitized:bool,effect_nonce:string|null,effect_created_at:int|null,exit_code:int|null,finished_at:string|null}
     */
    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->targetReceipt($this->reads->target($this->reads->build($operation, false), ValidatedData::string($input['slot'])), ValidatedData::string($input['command']), ValidatedData::string($run['effect_key'] ?? null));
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The UpCloud image effect does not match this build.', 422);
    }

    private function ordered(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$item) {
            $item = $this->ordered($item);
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
