<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\Instances\ContainerProcessCommands;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\RequiredMountPaths;
use App\Domain\Processes\VpDevPreset;
use App\Domain\ProjectDefinitions\DefinitionEnvironment;
use App\Domain\ProjectDefinitions\ProcessDefinitionSpecification;
use App\Models\ProcessDefinition;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use UnexpectedValueException;

final class ProcessDefinitionRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $inspectedData = [];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D',
            ],
            'environments' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'environments.*' => ['required', 'string', 'distinct:strict', Rule::enum(DefinitionEnvironment::class)],
            'spec' => ['required', 'array'],
            'spec.runtime' => ['required', Rule::enum(ProcessRuntime::class)],
            'spec.command' => ['required', 'array', 'list', 'min:1', 'max:64'],
            'spec.command.*' => ['string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'spec.image' => [
                'required_if:spec.runtime,docker',
                'prohibited_unless:spec.runtime,docker',
                'nullable',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/:@-]*\z/D',
                'not_regex:/[\s\x00-\x1F]/',
            ],
            'spec.working_directory' => [
                'sometimes',
                'nullable',
                'filled',
                'string',
                'max:4096',
                'regex:/\A\/(?!.*(?:^|\/)\.\.(?:\/|$))[^\x00\r\n]*\z/D',
            ],
            'spec.environment' => ['sometimes', 'array', 'max:100'],
            'spec.environment.*' => ['string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'spec.ports' => ['sometimes', 'prohibited_unless:spec.runtime,docker', 'array', 'list', 'max:100'],
            'spec.ports.*' => [
                'string',
                'regex:/\A(?:(?:\d{1,3}\.){3}\d{1,3}:)?\d{1,5}:\d{1,5}(?:\/(?:tcp|udp))?\z/D',
            ],
            'spec.volumes' => ['sometimes', 'prohibited_unless:spec.runtime,docker', 'array', 'list', 'max:100'],
            'spec.volumes.*' => ['array:source,target,read_only'],
            'spec.volumes.*.source' => [
                'required',
                'string',
                'max:4096',
                'regex:/\A(?:\/[A-Za-z0-9._\/ -]+|[A-Za-z0-9][A-Za-z0-9_.-]*)\z/D',
            ],
            'spec.volumes.*.target' => [
                'required',
                'string',
                'max:4096',
                'regex:/\A\/[A-Za-z0-9._\/ -]*\z/D',
            ],
            'spec.volumes.*.read_only' => ['sometimes', 'boolean'],
            'spec.restart_policy' => [
                'sometimes',
                'nullable',
                Rule::in(['never', 'on-failure', 'always', 'unless-stopped']),
            ],
            'spec.keep_alive' => ['sometimes', 'nullable', 'boolean'],
            'spec.restart_on_deploy' => ['sometimes', 'nullable', 'boolean'],
            'spec.environment_file' => ['sometimes', 'nullable', 'string', 'max:4096', 'regex:/\A\/(?!.*(?:^|\/)\.\.(?:\/|$))[^\x00\r\n]*\z/D'],
            'spec.required_mount_paths' => ['sometimes', 'array', 'list', 'max:64'],
            'spec.required_mount_paths.*' => ['required', 'string'],
            'spec.preset' => ['required_with:spec.preset_version', Rule::in([VpDevPreset::NAME])],
            'spec.preset_version' => ['sometimes', 'integer', Rule::in([1])],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $this->inspectedData = app(ProjectDefinitionJsonInspector::class)->inspectProcess($this->getContent());

            $data = $this->inspectedData;
            $definition = $this->route('processDefinition');
            if (is_array($data['spec'] ?? null)) {
                $data['spec'] = ProcessDefinitionSpecification::update(
                    $definition instanceof ProcessDefinition ? $definition->spec : [], ValidatedData::object($data['spec']),
                );
            }

            return $data;
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateSystemdExecutable($validator, $validator->getData());
            $this->validateEnvironmentNames($validator, $validator->getData());
            $this->validatePorts($validator, $validator->getData());
            $this->validateDevelopmentSpecification($validator, $validator->getData());
        }];
    }

    public function payload(): ProjectDefinitionInputData
    {
        $validated = $this->validated();
        $spec = ValidatedData::object($this->route('processDefinition') instanceof ProcessDefinition
            ? ($this->inspectedData['spec'] ?? null) : ($validated['spec'] ?? null));
        if (is_array($spec['volumes'] ?? null)) {
            foreach ($spec['volumes'] as &$volume) {
                if (is_array($volume)) {
                    if (! is_string($volume['read_only'] ?? null) || ! str_contains($volume['read_only'], '[REDACTED]')) {
                        $volume['read_only'] = in_array($volume['read_only'] ?? false, [true, 1, '1'], strict: true);
                    }
                }
            }
            unset($volume);
        }

        return new ProjectDefinitionInputData(
            name: ValidatedData::string($validated['name'] ?? null),
            environments: ValidatedData::stringList($validated['environments'] ?? null),
            spec: $spec,
        );
    }

    /** @param array<string, mixed> $data */
    private function validateSystemdExecutable(Validator $validator, array $data): void
    {
        if (data_get($data, 'spec.runtime') !== ProcessRuntime::Systemd->value) {
            return;
        }

        $executable = data_get($data, 'spec.command.0');

        if (is_string($executable) && str_starts_with($executable, '/')) {
            return;
        }

        if (($data['environments'] ?? []) === [DefinitionEnvironment::Development->value]
            && is_string($executable) && app(ContainerProcessCommands::class)->supportsName($executable)) {
            return;
        }

        $validator->errors()->add('spec.command.0', 'The systemd executable must be an absolute path.');
    }

    /** @param array<string, mixed> $data */
    private function validateDevelopmentSpecification(Validator $validator, array $data): void
    {
        $spec = ValidatedData::object($data['spec'] ?? null);
        $development = ($data['environments'] ?? []) === [DefinitionEnvironment::Development->value]
            && ($spec['runtime'] ?? null) === ProcessRuntime::Systemd->value;
        foreach (['restart_on_deploy', 'environment_file', 'required_mount_paths', 'preset', 'preset_version'] as $field) {
            if (array_key_exists($field, $spec) && ! $development) {
                $validator->errors()->add("spec.{$field}", 'This field is supported only by development-only systemd definitions.');
            }
        }
        if (array_key_exists('environment', $spec) && ! $development && ($spec['runtime'] ?? null) !== ProcessRuntime::Docker->value) {
            $validator->errors()->add('spec.environment', 'Environment values require Docker or a development-only systemd definition.');
        }
        if (array_key_exists('required_mount_paths', $spec)) {
            try {
                RequiredMountPaths::parse($spec['required_mount_paths']);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('spec.required_mount_paths', $exception->getMessage());
            }
        }
        $definition = $this->route('processDefinition');
        $existingSpec = $definition instanceof ProcessDefinition ? $definition->spec : [];
        $capturedPreset = $existingSpec['preset'] ?? null;
        if (array_key_exists('preset', $spec) || $capturedPreset !== null) {
            if (! $development || $capturedPreset !== VpDevPreset::NAME
                || ($spec['preset'] ?? null) !== $capturedPreset
                || ($spec['preset_version'] ?? 1) !== ($existingSpec['preset_version'] ?? 1)
                || ($spec['command'] ?? null) !== VpDevPreset::definitionCommand()
                || (array_key_exists('preset_version', ValidatedData::object($this->inspectedData['spec'] ?? null))
                    && data_get($this->inspectedData, 'spec.preset_version') === null)) {
                $validator->errors()->add('spec.preset', 'Only an existing captured vp-dev definition with its canonical command and preset metadata can be edited.');
            }
            if (($spec['required_mount_paths'] ?? []) !== []) {
                $validator->errors()->add('spec.required_mount_paths', 'The preset owns this configuration.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateEnvironmentNames(Validator $validator, array $data): void
    {
        $environment = data_get($data, 'spec.environment');

        if (! is_array($environment)) {
            return;
        }

        foreach (array_keys($environment) as $name) {
            if (is_string($name) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1) {
                continue;
            }

            $validator->errors()->add('spec.environment', 'The environment contains an invalid variable name.');

            return;
        }
    }

    /** @param array<string, mixed> $data */
    private function validatePorts(Validator $validator, array $data): void
    {
        $ports = data_get($data, 'spec.ports');

        if (! is_array($ports)) {
            return;
        }

        foreach ($ports as $index => $port) {
            if (! is_string($port)) {
                continue;
            }

            $segments = explode(':', explode('/', $port, limit: 2)[0]);
            $numericPorts = array_slice($segments, -2);
            $valid = count($numericPorts) === 2;

            foreach ($numericPorts as $numericPort) {
                $number = filter_var($numericPort, FILTER_VALIDATE_INT);
                $valid = $valid && is_int($number) && $number >= 1 && $number <= 65_535;
            }

            if (! $valid) {
                $validator->errors()->add("spec.ports.{$index}", 'Published ports must be between 1 and 65535.');
            }
        }
    }
}
