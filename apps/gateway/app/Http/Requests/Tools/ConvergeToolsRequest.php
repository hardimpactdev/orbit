<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

final class ConvergeToolsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['tool_ids' => ['sometimes', 'array', 'list', 'min:1'],
            'tool_ids.*' => ['required', 'integer', 'min:1', 'distinct']];
    }

    /** @return list<int>|null */
    public function toolIds(): ?array
    {
        $ids = $this->validated('tool_ids');
        if ($ids === null) {
            return null;
        }
        assert(is_array($ids) && array_is_list($ids));

        return array_map(static function (mixed $id): int {
            $integer = filter_var($id, FILTER_VALIDATE_INT);
            assert(is_int($integer));

            return $integer;
        }, $ids);
    }
}
