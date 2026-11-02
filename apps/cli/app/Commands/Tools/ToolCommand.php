<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use JsonException;
use Orbit\Sdk\Responses\Tools\ToolResponse;

abstract class ToolCommand extends GatewayCommand
{
    /** @return array<string, mixed>|null|false */
    protected function repositoryDefinition(mixed $json): array|null|false
    {
        if ($json === null) {
            return null;
        }
        try {
            if (! is_string($json)) {
                throw new JsonException;
            }
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
            if ($object instanceof \stdClass) {
                $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new JsonException;
                }
                $value = [];
                foreach ($decoded as $key => $item) {
                    if (! is_string($key)) {
                        throw new JsonException;
                    }
                    $value[$key] = $item;
                }

                return $value;
            }
        } catch (JsonException) {
        }
        $this->renderGatewayFailure('tool.repository_invalid', 'Repository must be a JSON object.');

        return false;
    }

    protected function nodeId(): ?int
    {
        $node = filter_var($this->option('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (is_int($node)) {
            return $node;
        }
        $this->renderGatewayFailure('tool.node_id_invalid', 'Node ID must be a positive integer.');

        return null;
    }

    protected function toolId(): ?int
    {
        return $this->positiveId('tool', 'Tool', 'tool.id_invalid');
    }

    protected function writeToolJson(ToolResponse $response): void
    {
        $this->writeJson($response->toArray());
    }

    protected function renderTool(ToolResponse $tool, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeToolJson($tool);

            return self::SUCCESS;
        }

        $repository = $tool->repository === null ? [] : ['Repository' => json_encode($tool->repository, JSON_UNESCAPED_SLASHES)];
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            ...$repository,
            'ID' => $tool->id,
            'Node ID' => $tool->nodeId,
            'Manager' => $tool->manager,
            'Package' => $tool->package,
            'Constraint' => $tool->versionConstraint,
            'Status' => $tool->status,
            'Installed version' => $tool->installedVersion,
            'Failed operation' => $tool->failedOperation,
            'Error code' => $tool->errorCode,
            'Outcome' => $tool->outcome,
            'Request ID' => $tool->requestId,
        ]));

        return self::SUCCESS;
    }
}
