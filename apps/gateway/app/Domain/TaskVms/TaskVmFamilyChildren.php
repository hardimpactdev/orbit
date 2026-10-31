<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * @phpstan-type ChildIntent array{key:string,kind:string,node_id:int,input:array<string,mixed>}
 * Reads the stored Node intents retained by enrollment admission.
 */
final readonly class TaskVmFamilyChildren
{
    /** @return list<ChildIntent> */
    public function nodeIntents(Operation $operation): array
    {
        $stored = $operation->state['child_intents'] ?? [];
        if (! is_array($stored) || ! array_is_list($stored)) {
            throw new \LogicException('The VM Node intents must be a list.');
        }

        return array_map(static function (mixed $value): array {
            $intent = ValidatedData::object($value);

            return ['key' => ValidatedData::string($intent['key']), 'kind' => ValidatedData::string($intent['kind']),
                'node_id' => ValidatedData::integer($intent['node_id']), 'input' => ValidatedData::object($intent['input'])];
        }, $stored);
    }
}
