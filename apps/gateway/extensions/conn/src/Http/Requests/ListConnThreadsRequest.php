<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Narrows the thread index to one Project's or one Instance's servers; without either it lists every server the caller reaches. */
final class ListConnThreadsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'project' => ['sometimes', 'integer', 'min:1'],
            'instance' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function projectId(): ?int
    {
        return $this->has('project') ? $this->integer('project') : null;
    }

    public function instanceId(): ?int
    {
        return $this->has('instance') ? $this->integer('instance') : null;
    }
}
