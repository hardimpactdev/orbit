<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

final class ResolveAutomaticDeployAdmissionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [...new AutomaticDeployReceiptRequest()->rules(), 'caller_node_id' => ['sometimes', 'integer', 'min:1', 'exists:nodes,id']];
    }

    public function originalCaller(): Node
    {
        $id = $this->validated('caller_node_id');
        $caller = $id === null ? $this->user() : Node::query()->findOrFail($id);
        assert($caller instanceof Node);

        return $caller;
    }

    public function commit(): string
    {
        $value = $this->validated('commit');
        assert(is_string($value));

        return $value;
    }

    public function requestKey(): string
    {
        $value = $this->validated('request_key');
        assert(is_string($value));

        return $value;
    }
}
