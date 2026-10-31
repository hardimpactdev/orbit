<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Support\ValidatedData;
use InvalidArgumentException;

/** Typed reads of already admitted family data, also used when projecting old receipts. */
final class TaskVmInputs
{
    /** @return list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}> */
    public static function artifacts(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Artifact intent must be a list.');
        }
        $result = [];
        foreach ($value as $entry) {
            $entry = ValidatedData::object($entry);
            $result[] = ['manager' => ValidatedData::string($entry['manager']), 'package' => ValidatedData::string($entry['package']),
                'version' => ValidatedData::string($entry['version']), 'url' => ValidatedData::string($entry['url']),
                'integrity' => ValidatedData::nullableString($entry['integrity']), 'key' => ValidatedData::string($entry['key'])];
        }

        return $result;
    }

    /** @return list<array{project_id:int,blobs:array<string,string>,artifacts:list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}>}> */
    public static function snapshots(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Snapshot intent must be a list.');
        }
        $result = [];
        foreach ($value as $entry) {
            $entry = ValidatedData::object($entry);
            $result[] = ['project_id' => ValidatedData::integer($entry['project_id']), 'blobs' => ValidatedData::stringMap($entry['blobs']), 'artifacts' => self::artifacts($entry['artifacts'])];
        }

        return $result;
    }

    /** @return list<array{route_id:int,node_id:int,instance_id:int}> */
    public static function workspaceRoutes(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Workspace Routes must be a list.');
        }
        $routes = [];
        foreach ($value as $entry) {
            $entry = ValidatedData::object($entry);
            $routes[] = ['route_id' => ValidatedData::integer($entry['route_id']), 'node_id' => ValidatedData::integer($entry['node_id']), 'instance_id' => ValidatedData::integer($entry['instance_id'])];
        }

        return $routes;
    }
}
