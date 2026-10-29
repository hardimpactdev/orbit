<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;
use stdClass;

/** Wire-only shapes for the executor protocol's maps. Never changes stored evidence or resolves secrets. */
final readonly class OperationExecutorJson
{
    public function __construct(private OperationTransports $transports) {}

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function operation(Operation $operation, array $record): array
    {
        $record['input'] = (object) ValidatedData::object($record['input']);
        $record = self::receipt($record);
        $steps = ValidatedData::object($record['step_results']);
        foreach ($steps as $name => $result) {
            $steps[$name] = (object) ValidatedData::object($result);
        }
        $record['step_results'] = (object) $steps;
        $context = ValidatedData::object($record['execution_context']);
        if (! is_array($context['descriptors'])) {
            throw new LogicException('An execution context requires a descriptor list.');
        }
        $descriptors = [];
        foreach ($context['descriptors'] as $descriptor) {
            $descriptor = ValidatedData::object($descriptor);
            $run = ['worker' => $operation->worker ?? '', 'key' => ValidatedData::string($descriptor['key']),
                'transport' => ValidatedData::string($descriptor['transport']), 'name' => ValidatedData::string($descriptor['name']),
                'input' => ValidatedData::object($descriptor['input'])];
            $schema = $this->transports->get($run['transport'])->schema($operation, $run);
            $descriptor['input'] = AgentSchemas::wireValue((object) $run['input'], $schema);
            $descriptors[] = $descriptor;
        }
        $context['descriptors'] = $descriptors;
        if (array_key_exists('result', $context)) {
            $context = self::receipt($context);
        }
        $record['execution_context'] = (object) $context;

        return $record;
    }

    /** Operation, child and effect receipts all have a nullable result map.
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public static function receipt(array $receipt): array
    {
        if ($receipt['result'] !== null) {
            $receipt['result'] = (object) ValidatedData::object($receipt['result']);
        }

        return $receipt;
    }

    /** @param array<string,mixed> $result */
    public static function result(array $result): stdClass
    {
        return (object) $result;
    }
}
