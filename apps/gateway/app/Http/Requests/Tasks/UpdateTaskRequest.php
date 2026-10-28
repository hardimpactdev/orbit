<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Rules\CommandPaths;
use App\Rules\DistinctDeliverableIds;
use App\Rules\FailsOnBase;
use App\Rules\TaskTopologyList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateTaskRequest extends FormRequest
{
    use ValidatesDeliverablePaths;

    /** @return array<string, list<string|Enum|In|ProhibitedIf|DistinctDeliverableIds|FailsOnBase|CommandPaths|TaskTopologyList>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'brief' => ['sometimes', 'string', 'max:8000'],
            'position' => ['sometimes', 'integer:strict', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(TaskStatus::class)],
            'topology' => ['sometimes', 'array', 'list', 'max:'.TaskTopology::maxNodes(), new TaskTopologyList],
            'topology.*' => ['required', 'string', 'max:64', 'regex:'.TaskTopology::EntryPattern],
            'deliverables' => ['sometimes', 'array', 'list', 'max:5', new DistinctDeliverableIds],
            'deliverables.*' => ['required', 'array:id,type,description,path,change,command,directory,fails_on_base,paths'],
            'deliverables.*.id' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'deliverables.*.type' => ['required', 'string', Rule::enum(TaskDeliverableType::class)],
            'deliverables.*.description' => ['required', 'string', 'max:500'],
            'deliverables.*.path' => ['required_if:deliverables.*.type,file', 'prohibited_unless:deliverables.*.type,file', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'deliverables.*.change' => ['required_if:deliverables.*.type,file', 'prohibited_unless:deliverables.*.type,file', 'string', Rule::in(['created', 'modified', 'any'])],
            'deliverables.*.fails_on_base' => ['sometimes', new FailsOnBase, 'boolean:strict'],
            'deliverables.*.command' => ['required_if:deliverables.*.type,command', 'prohibited_unless:deliverables.*.type,command', 'string', 'max:1000'],
            'deliverables.*.directory' => ['sometimes', 'prohibited_unless:deliverables.*.type,command', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'deliverables.*.paths' => [new CommandPaths, 'prohibited_unless:deliverables.*.type,command', 'array', 'list', 'max:100'],
            'deliverables.*.paths.*' => ['required', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['title', 'brief', 'position', 'deliverables', 'topology', 'status']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): UpdateTaskData
    {
        $position = $this->validated('position');

        return new UpdateTaskData(
            title: is_string($this->validated('title')) ? $this->validated('title') : null,
            brief: is_string($this->validated('brief')) ? $this->validated('brief') : null,
            position: is_int($position) ? $position : null,
            status: is_string($this->validated('status')) ? TaskStatus::from($this->validated('status')) : null,
            topology: array_key_exists('topology', $this->validated()) ? TaskTopology::fromInput($this->validated('topology')) : null,
            deliverables: array_key_exists('deliverables', $this->validated()) ? CreateTaskRequest::deliverables($this->validated('deliverables')) : null,
        );
    }
}
