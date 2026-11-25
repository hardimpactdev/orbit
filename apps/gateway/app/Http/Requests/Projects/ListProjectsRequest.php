<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

final class ListProjectsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** The Node whose generic Project the caller looks up (projects.md#find-the-project-of-a-node), or null for every Project. */
    public function nodeId(): ?int
    {
        return $this->has('node_id') ? $this->integer('node_id') : null;
    }
}
